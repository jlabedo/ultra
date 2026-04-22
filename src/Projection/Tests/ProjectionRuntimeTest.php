<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\Coordination\InMemoryProjectionCoordinatorLeaseRepository;
use Ultra\Projection\Coordination\InMemoryProjectionWakeQueue;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\Coordination\ProjectionCoordinator;
use Ultra\Projection\Coordination\WakeProjectionCoordinator;
use Ultra\Projection\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionRunner;

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

#[CoversClass(ProjectionActivityRecorder::class)]
#[CoversClass(ProjectionCoordinator::class)]
#[CoversClass(DefaultProjectionScalingPolicy::class)]
final class ProjectionRuntimeTest extends TestCase
{
    public function test_activity_recorder_deduplicates_buckets_per_append_and_enqueues_a_single_coordinator_wakeup(): void
    {
        $projection = new RuntimeProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $catalog = new ProjectionCatalog($runtime);
        $activity = new InMemoryProjectionBucketActivityRepository();
        $leases = new InMemoryProjectionCoordinatorLeaseRepository();
        $queue = new InMemoryProjectionWakeQueue();
        $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $queue);

        $recorder->record([
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math again'], ['courseId' => 'course-1']),
        ]);

        $pending = $activity->pendingBuckets('runtime_projection');

        self::assertCount(1, $pending);
        self::assertSame(1, $pending[0]->counter);
        self::assertInstanceOf(WakeProjectionCoordinator::class, $queue->dequeue());
        self::assertNull($queue->dequeue());
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
        $queue = new InMemoryProjectionWakeQueue();
        $recorder = new ProjectionActivityRecorder($catalog, $activity, $leases, $queue);
        $runner = new ProjectionRunner($eventStore, $segments);
        $coordinator = new ProjectionCoordinator(
            $catalog,
            $runner,
            $activity,
            $leases,
            $queue,
            new DefaultProjectionScalingPolicy(),
        );

        $events = [
            new Event('course.created', ['courseId' => 'course-1', 'name' => 'Math'], ['courseId' => 'course-1']),
            new Event('course.created', ['courseId' => 'course-2', 'name' => 'Physics'], ['courseId' => 'course-2']),
        ];

        $eventStore->append($events);
        $recorder->record($events);

        self::assertInstanceOf(WakeProjectionCoordinator::class, $queue->dequeue());

        $result = $coordinator->run('runtime_projection', 'coordinator-1');

        self::assertTrue($result->acquiredLease);
        self::assertSame(2, $result->processedEvents);
        self::assertSame([
            'course-1' => 'Math',
            'course-2' => 'Physics',
        ], $projection->names);
        self::assertSame([], $activity->pendingBuckets('runtime_projection'));
    }
}
