<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\Coordination\ProjectionBucketActivity;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\InMemory\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\InMemory\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionDefinition;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;

#[EventAttribute('course.created')]
final readonly class RuntimeCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'runtime_projection', partitionBy: 'courseId')]
final class RuntimeProjection
{
    /** @var array<string, string> */
    public array $names = [];

    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }
}

#[Projection(name: 'secondary_runtime_projection', partitionBy: 'courseId')]
final class SecondaryRuntimeProjection
{
    #[ProjectionHandler]
    public function onCourseCreated(RuntimeCourseCreated $event): void {}
}

/**
 * @internal
 */
#[CoversClass(ProjectionActivityRecorder::class)]
#[CoversClass(ProjectionCoordinator::class)]
#[CoversClass(DefaultProjectionScalingPolicy::class)]
#[CoversClass(ProjectionCatalog::class)]
#[CoversClass(ProjectionRuntime::class)]
final class ProjectionRuntimeTest extends TestCase
{
    public function test_activity_recorder_deduplicates_buckets_per_append_and_enqueues_a_single_coordinator_wakeup(): void
    {
        $projection = new RuntimeProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $activator);

        $recorder->record([
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math again'], ['courseId' => 'course-1']),
        ]);

        $pending = $activity->pendingBucketsForSegment('runtime_projection', 0, 255);

        self::assertCount(1, $pending);
        self::assertSame(1, $pending[0]->counter);
        self::assertSame(1, $activator->workSignals());
        self::assertSame([], $activator->workerClaims());
    }

    public function test_activity_recorder_ignores_events_missing_the_partition_dimension(): void
    {
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $recorder = new ProjectionActivityRecorder(new ProjectionCatalog($runtime), $activity, $leases, $activator);

        $recorder->record([
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['studentId' => 'student-1']),
            new Event('unrelated.event', [], ['courseId' => 'course-1']),
        ]);

        self::assertSame([], $activity->pendingBucketsForSegment('runtime_projection', 0, 255));
        self::assertSame(0, $activator->workSignals());
    }

    public function test_activity_recorder_continues_past_unhandled_and_unpartitioned_events_to_record_later_matches(): void
    {
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $secondaryRuntime = new ProjectionRuntime(new SecondaryRuntimeProjection(), new UltraNormalizer());
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $recorder = new ProjectionActivityRecorder(
            new ProjectionCatalog($runtime, $secondaryRuntime),
            $activity,
            $leases,
            $activator,
        );

        $recorder->record([
            new Event('unrelated.event', [], ['courseId' => 'course-x']),
            new Event('course.created', ['courseId' => 'course-missing', 'name' => 'Ignored'], ['studentId' => 'student-1']),
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
        ]);

        $primaryPending = $activity->pendingBucketsForSegment('runtime_projection', 0, 255);
        $secondaryPending = $activity->pendingBucketsForSegment('secondary_runtime_projection', 0, 255);

        self::assertCount(1, $primaryPending);
        self::assertSame(1, $primaryPending[0]->counter);
        self::assertCount(1, $secondaryPending);
        self::assertSame(1, $secondaryPending[0]->counter);
        self::assertSame(1, $activator->workSignals());
    }

    public function test_coordinator_catches_up_a_stale_projection_and_acknowledges_processed_buckets(): void
    {
        $projection = new RuntimeProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $activator);
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
            $leases,
            $activator,
            new DefaultProjectionScalingPolicy(),
        );

        $events = [
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
            new Event('course.created', ['courseId' => 'course-2', 'name' => 'Physics'], ['courseId' => 'course-2']),
        ];

        $eventStore->append($events);
        $recorder->record($events);

        $result = $coordinator->run('coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(2, $result->processedEvents);
        self::assertSame([
            'course-1' => 'Math',
            'course-2' => 'Physics',
        ], $projection->names);
        self::assertSame([], $activity->pendingBucketsForSegment('runtime_projection', 0, 255));
    }

    public function test_global_coordinator_can_catch_up_multiple_projections_in_one_run(): void
    {
        $primaryProjection = new RuntimeProjection();
        $primaryRuntime = new ProjectionRuntime($primaryProjection, new UltraNormalizer());
        $secondaryProjection = new SecondaryRuntimeProjection();
        $secondaryRuntime = new ProjectionRuntime($secondaryProjection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($primaryRuntime, $secondaryRuntime);
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $activator = new InMemoryProjectionActivator();
        $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $activator);
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $coordinator = new ProjectionCoordinator($catalog, $runner, $activity, $leases, $activator);

        $events = [
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
        ];

        $eventStore->append($events);
        $recorder->record($events);

        $result = $coordinator->run('coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(2, $result->processedEvents);
        self::assertSame(2, $result->acknowledgedBuckets);
        self::assertSame(['course-1' => 'Math'], $primaryProjection->names);
        self::assertSame([], $activity->pendingBucketsForSegment('runtime_projection', 0, 255));
        self::assertSame([], $activity->pendingBucketsForSegment('secondary_runtime_projection', 0, 255));
    }

    public function test_projection_catalog_tracks_constructor_and_added_runtimes(): void
    {
        $runtime = new ProjectionRuntime(new RuntimeProjection(), new UltraNormalizer());
        $secondaryRuntime = new ProjectionRuntime(new SecondaryRuntimeProjection(), new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);

        $catalog->add($secondaryRuntime);

        self::assertSame($runtime, $catalog->runtime('runtime_projection'));
        self::assertSame($secondaryRuntime, $catalog->runtime('secondary_runtime_projection'));
        self::assertSame($runtime->definition(), $catalog->definition('runtime_projection'));
        self::assertSame(
            ['runtime_projection', 'secondary_runtime_projection'],
            array_map(
                static fn (ProjectionDefinition $definition): string => $definition->projectionId,
                $catalog->definitions(),
            ),
        );
    }

    public function test_projection_catalog_throws_for_unknown_projections(): void
    {
        $catalog = new ProjectionCatalog();

        try {
            $catalog->runtime('missing_projection');
            self::fail('Expected runtime lookup to fail for an unknown projection.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown projection "missing_projection"', $e->getMessage());
        }

        try {
            $catalog->definition('missing_projection');
            self::fail('Expected definition lookup to fail for an unknown projection.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unknown projection "missing_projection"', $e->getMessage());
        }
    }

    public function test_projection_runtime_uses_an_explicit_definition_when_one_is_provided(): void
    {
        $definition = ProjectionDefinition::fromClass(RuntimeProjection::class);
        $runtime = new ProjectionRuntime(new \stdClass(), new UltraNormalizer(), $definition);

        self::assertSame('runtime_projection', $runtime->projectionId());
        self::assertSame($definition, $runtime->definition());
    }

    public function test_default_projection_scaling_policy_returns_zero_worker_wakeups(): void
    {
        $policy = new DefaultProjectionScalingPolicy();

        self::assertSame(0, $policy->additionalWorkersRequested(
            [new ProjectionBucketActivity('runtime_projection', 12, 3, 1)],
        ));
    }
}
