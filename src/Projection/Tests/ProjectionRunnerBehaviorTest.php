<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Projection\InMemory\InMemoryProjectionBucketActivityRepository;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\NoOpProjectionTransactionManager;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionTransactionManagerInterface;
use Ultra\Projection\ProjectionWorkAssignment;

final class CallbackEventStore implements EventStore
{
    public function __construct(
        private readonly EventStore $inner,
        private ?\Closure $beforeLoad = null,
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        if (null !== $this->beforeLoad) {
            ($this->beforeLoad)();
            $this->beforeLoad = null;
        }

        return $this->inner->load($query, $from, $to, $batchSize);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->inner->append($events, ...$appendConditions);
    }
}

#[EventAttribute('runner.course.created')]
final readonly class RunnerCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'tracking_projection', partitionBy: 'courseId')]
final class TrackingProjection
{
    public int $initialized = 0;

    /** @var array<string, string> */
    public array $names = [];

    public int $flushes = 0;

    #[ProjectionInitializer]
    public function initialize(): void
    {
        ++$this->initialized;
    }

    #[ProjectionHandler]
    public function onCourseCreated(RunnerCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        ++$this->flushes;
    }
}

#[Projection(name: 'failing_tracking_projection', partitionBy: 'courseId')]
final class FailingTrackingProjection
{
    public int $initialized = 0;

    #[ProjectionInitializer]
    public function initialize(): void
    {
        ++$this->initialized;
    }

    #[ProjectionHandler]
    public function onCourseCreated(RunnerCourseCreated $event): void
    {
        throw new \RuntimeException('Projection handler failed.');
    }
}

#[Projection(name: 'failing_initializer_projection', partitionBy: 'courseId')]
final class FailingInitializerProjection
{
    #[ProjectionInitializer]
    public function initialize(): void
    {
        throw new \RuntimeException('Projection initializer failed.');
    }
}

final class RecordingProjectionTransactionManager implements ProjectionTransactionManagerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly ?InMemoryProjectionSegmentRepository $segments = null,
    ) {}

    public function transactional(callable $operation): mixed
    {
        $this->events[] = 'begin';
        $snapshot = $this->snapshotSegments();

        try {
            $result = $operation();
            $this->events[] = 'commit';

            return $result;
        } catch (\Throwable $exception) {
            $this->restoreSegments($snapshot);
            $this->events[] = 'rollback';

            throw $exception;
        }
    }

    private function snapshotSegments(): ?array
    {
        if (null === $this->segments) {
            return null;
        }

        $property = new \ReflectionProperty($this->segments, 'segments');

        return $property->getValue($this->segments);
    }

    private function restoreSegments(?array $snapshot): void
    {
        if (null === $this->segments || null === $snapshot) {
            return;
        }

        $property = new \ReflectionProperty($this->segments, 'segments');
        $property->setValue($this->segments, $snapshot);
    }
}

/**
 * @internal
 */
#[CoversClass(ProjectionRunner::class)]
#[CoversClass(NoOpProjectionTransactionManager::class)]
final class ProjectionRunnerBehaviorTest extends TestCase
{
    public function test_ensure_initialized_runs_projection_initialization_once_inside_a_transaction(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager();
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $runner->ensureInitialized($runtime);
        $runner->ensureInitialized($runtime);

        self::assertSame(1, $projection->initialized);
        self::assertSame(['begin', 'commit', 'begin', 'commit'], $transactions->events);
        self::assertSame([
            ['bucketFrom' => 0, 'bucketTo' => 255, 'status' => 'available', 'pendingOperation' => null],
        ], $segments->listSegments('tracking_projection'));
    }

    public function test_ensure_initialized_rolls_back_segment_creation_when_initializer_fails(): void
    {
        $runtime = new ProjectionRuntime(new FailingInitializerProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager($segments);
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        try {
            $runner->ensureInitialized($runtime);
            self::fail('Expected the projection initializer to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection initializer failed.', $e->getMessage());
        }

        self::assertSame(['begin', 'rollback'], $transactions->events);
        self::assertSame([], $segments->listSegments('failing_initializer_projection'));
    }

    public function test_run_assigned_once_claims_only_its_own_assignment(): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments, activityRepository: $activity);

        $runner->ensureInitialized($runtime);
        $assignment = $runner->assignSegment('tracking_projection', 0, 'worker-1');

        self::assertNotNull($assignment);

        $result = $runner->runAssignedOnce(
            $runtime,
            new ProjectionWorkAssignment($assignment->assignmentId, 'tracking_projection', 0, 'worker-2'),
        );

        self::assertFalse($result->claimedSegment);
        self::assertSame('assigned', $segments->listSegments('tracking_projection')[0]['status']);
    }

    public function test_run_assigned_once_keeps_bucket_activity_pending_until_the_assignment_catches_up(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $bucket = BucketHasher::bucketFor('course-1');

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-1', 'Advanced Math'),
        ]);
        $activity->bump('tracking_projection', $bucket);

        $runner->ensureInitialized($runtime);
        $assignment = $runner->assignSegment('tracking_projection', 0, 'worker-1');

        self::assertNotNull($assignment);

        $firstResult = $runner->runAssignedOnce($runtime, $assignment, limit: 1);

        self::assertSame(1, $firstResult->processedEvents);
        self::assertFalse($firstResult->caughtUp);
        self::assertSame(0, $firstResult->acknowledgedBuckets);
        self::assertSame('assigned', $segments->listSegments('tracking_projection')[0]['status']);
        self::assertCount(1, $activity->pendingBucketsForSegment('tracking_projection', 0, 255));

        $secondResult = $runner->runAssignedOnce($runtime, $assignment, limit: 10);

        self::assertSame(1, $secondResult->processedEvents);
        self::assertTrue($secondResult->caughtUp);
        self::assertSame(1, $secondResult->acknowledgedBuckets);
        self::assertSame('available', $segments->listSegments('tracking_projection')[0]['status']);
        self::assertSame([], $activity->pendingBucketsForSegment('tracking_projection', 0, 255));
        self::assertSame(['course-1' => 'Advanced Math'], $projection->names);
    }

    public function test_run_assigned_once_decrements_only_the_seen_bucket_counters_after_a_concurrent_bump(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $innerStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $bucket = BucketHasher::bucketFor('course-1');

        $innerStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);
        $activity->bump('tracking_projection', $bucket);

        $runner = new ProjectionRunner(
            new CallbackEventStore($innerStore, static function () use ($activity, $bucket): void {
                $activity->bump('tracking_projection', $bucket);
            }),
            $segments,
            activityRepository: $activity,
        );
        $runner->ensureInitialized($runtime);
        $assignment = $runner->assignSegment('tracking_projection', 0, 'worker-1');

        self::assertNotNull($assignment);

        $result = $runner->runAssignedOnce($runtime, $assignment, limit: 10);
        $pending = $activity->pendingBucketsForSegment('tracking_projection', 0, 255);

        self::assertTrue($result->caughtUp);
        self::assertCount(1, $pending);
        self::assertSame($bucket, $pending[0]->bucket);
        self::assertSame(1, $pending[0]->counter);
    }

    public function test_run_assigned_once_does_not_decrement_bucket_activity_when_processing_fails(): void
    {
        $runtime = new ProjectionRuntime(new FailingTrackingProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new InMemoryProjectionBucketActivityRepository();
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $bucket = BucketHasher::bucketFor('course-1');

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);
        $activity->bump('failing_tracking_projection', $bucket);

        $runner->ensureInitialized($runtime);
        $assignment = $runner->assignSegment('failing_tracking_projection', 0, 'worker-1');

        self::assertNotNull($assignment);

        try {
            $runner->runAssignedOnce($runtime, $assignment, limit: 10);
            self::fail('Expected the projection handler to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection handler failed.', $e->getMessage());
        }

        self::assertCount(1, $activity->pendingBucketsForSegment('failing_tracking_projection', 0, 255));
        self::assertSame('available', $segments->listSegments('failing_tracking_projection')[0]['status']);
    }

    private static function courseCreatedEvent(string $courseId, string $name): Event
    {
        return new Event(
            eventType: 'runner.course.created',
            payload: ['courseId' => $courseId, 'name' => $name],
            domainIds: ['courseId' => $courseId],
        );
    }
}
