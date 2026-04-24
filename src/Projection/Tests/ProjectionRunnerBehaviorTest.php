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

#[Projection(name: 'failing_flush_tracking_projection', partitionBy: 'courseId')]
final class FailingFlushTrackingProjection
{
    public int $handled = 0;

    #[ProjectionHandler]
    public function onCourseCreated(RunnerCourseCreated $event): void
    {
        ++$this->handled;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        throw new \RuntimeException('Projection flush failed.');
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
    public function test_run_once_initializes_the_projection_only_when_the_segment_repository_is_first_created(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager();
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        self::assertSame(1, $runner->runOnce($runtime, 'worker-1'));
        self::assertSame(0, $runner->runOnce($runtime, 'worker-1'));
        self::assertSame(1, $projection->initialized);
        self::assertSame(2, $projection->flushes);
        self::assertSame(['course-1' => 'Math'], $projection->names);
        self::assertSame(['begin', 'commit', 'begin', 'commit', 'begin', 'commit', 'begin', 'commit'], $transactions->events);
        self::assertSame(1, $segments->readCurrentState('tracking_projection', 0)->checkpoint->gapFloor->value);
    }

    public function test_run_once_flushes_after_each_claimed_batch(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-2', 'Physics'),
        ]);

        self::assertSame(1, $runner->runOnce($runtime, 'worker-1', 1));
        self::assertSame(1, $runner->runOnce($runtime, 'worker-1', 1));
        self::assertSame(0, $runner->runOnce($runtime, 'worker-1', 1));

        self::assertSame(3, $projection->flushes);
        self::assertSame(['course-1' => 'Math', 'course-2' => 'Physics'], $projection->names);
    }

    public function test_run_once_result_reports_a_successful_claim_with_segment_details(): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager($segments);
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        $result = $runner->runOnceResult($runtime, 'worker-1');

        self::assertSame(1, $result->processedEvents);
        self::assertTrue($result->claimedSegment);
        self::assertNotNull($result->segment);
        self::assertSame(0, $result->segment->bucketFrom);
        self::assertSame(255, $result->segment->bucketTo);
        self::assertSame(['begin', 'commit', 'begin', 'commit'], $transactions->events);
    }

    public function test_run_once_wraps_initialization_in_a_transaction(): void
    {
        $runtime = new ProjectionRuntime(new FailingInitializerProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager($segments);
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        try {
            $runner->runOnce($runtime, 'worker-1');
            self::fail('Expected the projection initializer to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection initializer failed.', $e->getMessage());
        }

        self::assertSame(['begin', 'rollback'], $transactions->events);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Segment [0] not found');

        $segments->readCurrentState('failing_initializer_projection', 0);
    }

    public function test_run_once_releases_the_segment_without_advancing_the_checkpoint_when_a_handler_throws(): void
    {
        $runtime = new ProjectionRuntime(new FailingTrackingProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager();
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        try {
            $runner->runOnce($runtime, 'worker-1');
            self::fail('Expected the projection handler to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection handler failed.', $e->getMessage());
        }

        $segment = $segments->readCurrentState('failing_tracking_projection', 0);

        self::assertSame(['begin', 'commit', 'begin', 'rollback'], $transactions->events);
        self::assertSame('available', $segment->status);
        self::assertNull($segment->pendingOperation);
        self::assertSame(0, $segment->checkpoint->gapFloor->value);
    }

    public function test_run_once_releases_the_segment_without_advancing_the_checkpoint_when_flush_throws(): void
    {
        $projection = new FailingFlushTrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager();
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        try {
            $runner->runOnce($runtime, 'worker-1');
            self::fail('Expected the projection flush to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection flush failed.', $e->getMessage());
        }

        $segment = $segments->readCurrentState('failing_flush_tracking_projection', 0);

        self::assertSame(1, $projection->handled);
        self::assertSame(['begin', 'commit', 'begin', 'rollback'], $transactions->events);
        self::assertSame('available', $segment->status);
        self::assertNull($segment->pendingOperation);
        self::assertSame(0, $segment->checkpoint->gapFloor->value);
    }

    public function test_run_once_result_reports_when_no_segment_can_be_claimed(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager();
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $segments->ensureInitialized('tracking_projection');
        $segments->claimSegment('tracking_projection', 'other-worker');

        $result = $runner->runOnceResult($runtime, 'worker-1');

        self::assertSame(0, $result->processedEvents);
        self::assertFalse($result->claimedSegment);
        self::assertNull($result->segment);
        self::assertSame(0, $projection->initialized);
        self::assertSame(['begin', 'commit'], $transactions->events);
    }

    public function test_run_bucket_once_processes_only_the_segment_that_contains_the_requested_bucket(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);

        $segments->ensureInitialized('tracking_projection');
        $segments->splitSegment('tracking_projection', 0);
        $segments->reconcilePendingOperations('tracking_projection');

        $eventStore->append([
            self::courseCreatedEvent('course-2', 'Physics'),
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        $targetBucket = BucketHasher::bucketFor('course-1');

        self::assertSame(1, $runner->runBucketOnce($runtime, $targetBucket, 'worker-1'));
        self::assertSame(['course-1' => 'Math'], $projection->names);
        self::assertSame(0, $segments->readCurrentState('tracking_projection', 0)->checkpoint->gapFloor->value);
        self::assertSame(2, $segments->readCurrentState('tracking_projection', 128)->checkpoint->gapFloor->value);
    }

    public function test_run_bucket_once_result_reports_when_the_target_bucket_cannot_be_claimed(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments);

        $segments->ensureInitialized('tracking_projection');
        $segments->splitSegment('tracking_projection', 0);
        $segments->reconcilePendingOperations('tracking_projection');
        $segments->claimSegmentForBucket('tracking_projection', BucketHasher::bucketFor('course-1'), 'other-worker');

        $result = $runner->runBucketOnceResult($runtime, BucketHasher::bucketFor('course-1'), 'worker-1');

        self::assertSame(0, $result->processedEvents);
        self::assertFalse($result->claimedSegment);
        self::assertNull($result->segment);
        self::assertSame([], $projection->names);
    }

    public function test_run_once_leaves_a_deferred_split_for_reconciliation_when_the_segment_is_marked_during_processing(): void
    {
        $projection = new TrackingProjection();
        $segments = new InMemoryProjectionSegmentRepository();
        $store = new InMemoryEventStore();
        $store->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        $runner = new ProjectionRunner(
            new CallbackEventStore($store, static function () use ($segments): void {
                $segments->splitSegment('tracking_projection', 0);
            }),
            $segments,
        );
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());

        $result = $runner->runOnceResult($runtime, 'worker-1');

        self::assertTrue($result->claimedSegment);
        self::assertSame(['course-1' => 'Math'], $projection->names);

        $drained = $segments->readCurrentState('tracking_projection', 0);

        self::assertSame('drained', $drained->status);
        self::assertSame('split', $drained->pendingOperation);
        self::assertSame(1, $drained->checkpoint->gapFloor->value);

        $segments->reconcilePendingOperations('tracking_projection');

        self::assertSame(1, $segments->readCurrentState('tracking_projection', 0)->checkpoint->gapFloor->value);
        self::assertSame(1, $segments->readCurrentState('tracking_projection', 128)->checkpoint->gapFloor->value);
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
        self::assertSame('assigned', $segments->readCurrentState('tracking_projection', 0)->status);
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
        self::assertSame('assigned', $segments->readCurrentState('tracking_projection', 0)->status);
        self::assertCount(1, $activity->pendingBuckets('tracking_projection'));

        $secondResult = $runner->runAssignedOnce($runtime, $assignment, limit: 10);

        self::assertSame(1, $secondResult->processedEvents);
        self::assertTrue($secondResult->caughtUp);
        self::assertSame(1, $secondResult->acknowledgedBuckets);
        self::assertSame('available', $segments->readCurrentState('tracking_projection', 0)->status);
        self::assertSame([], $activity->pendingBuckets('tracking_projection'));
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
        $pending = $activity->pendingBuckets('tracking_projection');

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

        self::assertCount(1, $activity->pendingBuckets('failing_tracking_projection'));
        self::assertSame('available', $segments->readCurrentState('failing_tracking_projection', 0)->status);
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
