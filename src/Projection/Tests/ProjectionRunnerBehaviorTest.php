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
use Ultra\EventStore\Sequence;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Attribute\ProjectionInitializer;
use Ultra\Coordination\InMemory\InMemoryDirtyKeyTracker;
use Ultra\Projection\Attribute\ProjectionResetter;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\NoOpProjectionTransactionManager;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionTransactionManagerInterface;

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

    public int $resets = 0;

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

    #[ProjectionResetter]
    public function reset(): void
    {
        ++$this->resets;
        $this->names = [];
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

#[Projection(name: 'failing_resetter_projection', partitionBy: 'courseId')]
final class FailingResetterProjection
{
    #[ProjectionResetter]
    public function reset(): void
    {
        throw new \RuntimeException('Projection resetter failed.');
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
        $listed = $segments->listSegments('tracking_projection');
        self::assertCount(1, $listed);
        self::assertSame(0, $listed[0]->bucketFrom);
        self::assertSame(255, $listed[0]->bucketTo);
        self::assertNull($listed[0]->workerId);
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

    public function test_delete_projection_runs_projection_resetter_and_deletes_segments_inside_a_transaction(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager();
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $runner->ensureInitialized($runtime);
        $segments->splitSegment('tracking_projection', 0);
        $projection->names = ['course-1' => 'Math'];

        $runner->deleteProjection($runtime);

        self::assertSame(1, $projection->resets);
        self::assertSame([], $projection->names);
        self::assertSame([], $segments->listSegments('tracking_projection'));
        self::assertSame(['begin', 'commit', 'begin', 'commit'], $transactions->events);
    }

    public function test_delete_projection_rolls_back_segment_deletion_when_resetter_fails(): void
    {
        $runtime = new ProjectionRuntime(new FailingResetterProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $transactions = new RecordingProjectionTransactionManager($segments);
        $runner = new ProjectionRunner($eventStore, $segments, $transactions);

        $segments->ensureInitialized('failing_resetter_projection');

        try {
            $runner->deleteProjection($runtime);
            self::fail('Expected the projection resetter to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection resetter failed.', $e->getMessage());
        }

        self::assertSame(['begin', 'rollback'], $transactions->events);
        self::assertCount(1, $segments->listSegments('failing_resetter_projection'));
    }

    public function test_run_claimed_once_claims_only_its_own_worker_segment(): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments, activityRepository: $activity);

        $runner->ensureInitialized($runtime);
        self::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $result = $runner->runClaimedOnce(
            $runtime,
            'tracking_projection',
            0,
            'worker-2',
        );

        self::assertFalse($result->claimedSegment);
        self::assertSame('worker-1', $segments->listSegments('tracking_projection')[0]->workerId);
    }

    public function test_backfill_claimed_once_processes_a_segment_without_bucket_activity(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-2', 'Science'),
        ]);

        $runner->ensureInitialized($runtime);
        $firstResult = $runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 1);
        $secondResult = $runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 10);

        self::assertSame(1, $firstResult->processedEvents);
        self::assertFalse($firstResult->caughtUp);
        self::assertSame(1, $secondResult->processedEvents);
        self::assertTrue($secondResult->caughtUp);
        self::assertSame([
            'course-1' => 'Math',
            'course-2' => 'Science',
        ], $projection->names);
        self::assertSame([], $activity->affected());
        self::assertNull($segments->listSegments('tracking_projection')[0]->workerId);
    }

    public function test_run_claimed_once_keeps_bucket_activity_pending_until_the_claim_catches_up(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $bucket = BucketHasher::bucketFor('course-1');

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-1', 'Advanced Math'),
        ]);
        $activity->bump('tracking_projection', $bucket);

        $runner->ensureInitialized($runtime);
        self::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $firstResult = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 1);

        self::assertSame(1, $firstResult->processedEvents);
        self::assertFalse($firstResult->caughtUp);
        self::assertSame(0, $firstResult->acknowledgedBuckets);
        self::assertSame('worker-1', $segments->listSegments('tracking_projection')[0]->workerId);
        self::assertCount(1, $activity->pendingInSegment('tracking_projection', 0, 255));

        $secondResult = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 10);

        self::assertSame(1, $secondResult->processedEvents);
        self::assertTrue($secondResult->caughtUp);
        self::assertSame(1, $secondResult->acknowledgedBuckets);
        self::assertSame(null, $segments->listSegments('tracking_projection')[0]->workerId);
        self::assertSame([], $activity->pendingInSegment('tracking_projection', 0, 255));
        self::assertSame(['course-1' => 'Advanced Math'], $projection->names);
    }

    public function test_run_claimed_once_decrements_only_the_seen_bucket_counters_after_a_concurrent_bump(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $innerStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
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
        self::assertNotNull($runner->claimSegment('tracking_projection', 0, 'worker-1'));

        $result = $runner->runClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 10);
        $pending = $activity->pendingInSegment('tracking_projection', 0, 255);

        self::assertTrue($result->caughtUp);
        self::assertCount(1, $pending);
        self::assertSame($bucket, (int) $pending[0]->key);
        self::assertSame(1, $pending[0]->counter);
    }

    public function test_run_claimed_once_does_not_decrement_bucket_activity_when_processing_fails(): void
    {
        $runtime = new ProjectionRuntime(new FailingTrackingProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $bucket = BucketHasher::bucketFor('course-1');

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);
        $activity->bump('failing_tracking_projection', $bucket);

        $runner->ensureInitialized($runtime);
        self::assertNotNull($runner->claimSegment('failing_tracking_projection', 0, 'worker-1'));

        try {
            $runner->runClaimedOnce($runtime, 'failing_tracking_projection', 0, 'worker-1', limit: 10);
            self::fail('Expected the projection handler to throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Projection handler failed.', $e->getMessage());
        }

        self::assertCount(1, $activity->pendingInSegment('failing_tracking_projection', 0, 255));
        self::assertSame(null, $segments->listSegments('failing_tracking_projection')[0]->workerId);
    }

    public function test_merge_segments_projects_slower_segments_to_the_highest_checkpoint_before_replacing_them(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $activity = new ProjectionActivity(new InMemoryDirtyKeyTracker());
        $runner = new ProjectionRunner($eventStore, $segments, activityRepository: $activity);
        $leftCourseId = self::courseIdInBucketRange(0, 127);
        $rightCourseId = self::courseIdInBucketRange(128, 255);

        $eventStore->append([
            self::courseCreatedEvent($leftCourseId, 'Math'),
            self::courseCreatedEvent($rightCourseId, 'Science'),
        ]);

        $runner->ensureInitialized($runtime);
        $runner->splitSegment('tracking_projection', 0);
        self::assertNotNull($runner->claimSegment('tracking_projection', 128, 'worker-right'));

        $runner->runClaimedOnce($runtime, 'tracking_projection', 128, 'worker-right', limit: 10);

        self::assertSame([$rightCourseId => 'Science'], $projection->names);

        $runner->mergeSegments($runtime, 0, 255, 'coordinator-1', limit: 1);

        self::assertSame([
            $rightCourseId => 'Science',
            $leftCourseId => 'Math',
        ], $projection->names);

        $merged = $runner->claimSegment('tracking_projection', 0, 'worker-merged');
        self::assertNotNull($merged);
        self::assertSame(2, $merged->checkpoint->gapFloor->value);
    }

    public function test_merge_segments_combines_gap_sequence_numbers_when_checkpoint_floors_are_equal(): void
    {
        $runtime = new ProjectionRuntime(new TrackingProjection(), new UltraNormalizer());
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner(new InMemoryEventStore(), $segments, activityRepository: new ProjectionActivity(new InMemoryDirtyKeyTracker()));

        $runner->ensureInitialized($runtime);
        $runner->splitSegment('tracking_projection', 0);
        $left = $runner->claimSegment('tracking_projection', 0, 'worker-left');
        $right = $runner->claimSegment('tracking_projection', 128, 'worker-right');
        self::assertNotNull($left);
        self::assertNotNull($right);

        $segments->completeClaimedSegment($left, new Checkpoint(new Sequence(10), [new Sequence(12)]), 'worker-left', true);
        $segments->completeClaimedSegment($right, new Checkpoint(new Sequence(10), [new Sequence(13)]), 'worker-right', true);

        $runner->mergeSegments($runtime, 0, 255, 'coordinator-1');

        $merged = $runner->claimSegment('tracking_projection', 0, 'worker-merged');
        self::assertNotNull($merged);
        self::assertSame(10, $merged->checkpoint->gapFloor->value);
        self::assertSame([12, 13], $merged->checkpoint->gapSeqNoValues());
    }

    public function test_replay_from_beginning_rebuilds_projection_state_from_event_zero(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-2', 'Science'),
            self::courseCreatedEvent('course-3', 'History'),
        ]);

        // Prime the projection incrementally so there's existing state +
        // checkpoint to wipe.
        $runner->ensureInitialized($runtime);
        $runner->backfillClaimedOnce($runtime, 'tracking_projection', 0, 'worker-1', limit: 100);
        self::assertSame(
            ['course-1' => 'Math', 'course-2' => 'Science', 'course-3' => 'History'],
            $projection->names,
        );
        $resetsBefore = $projection->resets;

        // Replay from event 0.
        $processed = $runner->replayFromBeginning($runtime, limit: 2);

        self::assertSame(3, $processed);
        self::assertSame($resetsBefore + 1, $projection->resets, 'replay must reset projection state');
        self::assertSame(
            ['course-1' => 'Math', 'course-2' => 'Science', 'course-3' => 'History'],
            $projection->names,
        );
        // Every segment should be caught up (no claims left over).
        foreach ($segments->listSegments('tracking_projection') as $segment) {
            self::assertNull($segment->workerId);
        }
    }

    public function test_replay_from_beginning_is_idempotent(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
            self::courseCreatedEvent('course-2', 'Science'),
        ]);

        $first = $runner->replayFromBeginning($runtime);
        $expectedNames = $projection->names;
        $second = $runner->replayFromBeginning($runtime);

        self::assertSame(2, $first);
        self::assertSame(2, $second);
        self::assertSame($expectedNames, $projection->names);
    }

    public function test_replay_from_beginning_on_empty_event_store_returns_zero(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $runner = new ProjectionRunner(new InMemoryEventStore(), new InMemoryProjectionSegmentRepository());

        self::assertSame(0, $runner->replayFromBeginning($runtime));
        self::assertSame([], $projection->names);
        self::assertSame(1, $projection->initialized);
    }

    private static function courseCreatedEvent(string $courseId, string $name): Event
    {
        return new Event(
            eventType: 'runner.course.created',
            payload: ['courseId' => $courseId, 'name' => $name],
            domainIds: ['courseId' => $courseId],
        );
    }

    private static function courseIdInBucketRange(int $from, int $to): string
    {
        for ($i = 0; $i < 10000; ++$i) {
            $courseId = 'course-'.$i;
            $bucket = BucketHasher::bucketFor($courseId);
            if ($bucket >= $from && $bucket <= $to) {
                return $courseId;
            }
        }

        throw new \RuntimeException(sprintf('No course id found for bucket range [%d-%d].', $from, $to));
    }
}
