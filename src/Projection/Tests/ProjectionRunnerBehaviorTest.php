<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\BucketHasher;
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
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;

final class CallbackEventStore implements EventStore
{
    public function __construct(
        private readonly EventStore $inner,
        private ?Closure $beforeLoad = null,
    ) {}

    public function load(
        StreamQuery $query,
        ?\Ultra\EventStore\Checkpoint $from = null,
        ?\Ultra\EventStore\Checkpoint $to = null,
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
        throw new RuntimeException('Projection handler failed.');
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
        throw new RuntimeException('Projection flush failed.');
    }
}

#[CoversClass(ProjectionRunner::class)]
final class ProjectionRunnerBehaviorTest extends TestCase
{
    public function test_run_once_initializes_the_projection_only_when_the_segment_repository_is_first_created(): void
    {
        $projection = new TrackingProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        self::assertSame(1, $runner->runOnce($runtime, 'worker-1'));
        self::assertSame(0, $runner->runOnce($runtime, 'worker-1'));
        self::assertSame(1, $projection->initialized);
        self::assertSame(2, $projection->flushes);
        self::assertSame(['course-1' => 'Math'], $projection->names);
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
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        $result = $runner->runOnceResult($runtime, 'worker-1');

        self::assertSame(1, $result->processedEvents);
        self::assertTrue($result->claimedSegment);
        self::assertNotNull($result->segment);
        self::assertSame(0, $result->segment->bucketFrom);
        self::assertSame(255, $result->segment->bucketTo);
    }

    public function test_run_once_releases_the_segment_without_advancing_the_checkpoint_when_a_handler_throws(): void
    {
        $runtime = new ProjectionRuntime(new FailingTrackingProjection(), new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        try {
            $runner->runOnce($runtime, 'worker-1');
            self::fail('Expected the projection handler to throw.');
        } catch (RuntimeException $e) {
            self::assertSame('Projection handler failed.', $e->getMessage());
        }

        $segment = $segments->readCurrentState('failing_tracking_projection', 0);

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
        $runner = new ProjectionRunner($eventStore, $segments);

        $eventStore->append([
            self::courseCreatedEvent('course-1', 'Math'),
        ]);

        try {
            $runner->runOnce($runtime, 'worker-1');
            self::fail('Expected the projection flush to throw.');
        } catch (RuntimeException $e) {
            self::assertSame('Projection flush failed.', $e->getMessage());
        }

        $segment = $segments->readCurrentState('failing_flush_tracking_projection', 0);

        self::assertSame(1, $projection->handled);
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
        $runner = new ProjectionRunner($eventStore, $segments);

        $segments->ensureInitialized('tracking_projection');
        $segments->claimSegment('tracking_projection', 'other-worker');

        $result = $runner->runOnceResult($runtime, 'worker-1');

        self::assertSame(0, $result->processedEvents);
        self::assertFalse($result->claimedSegment);
        self::assertNull($result->segment);
        self::assertSame(0, $projection->initialized);
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

    private static function courseCreatedEvent(string $courseId, string $name): Event
    {
        return new Event(
            eventType: 'runner.course.created',
            payload: ['courseId' => $courseId, 'name' => $name],
            domainIds: ['courseId' => $courseId],
        );
    }
}
