<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\BucketHasher;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionFlush;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\InMemory\InMemoryProjectionSegmentRepository;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Signal\ProjectionSignalEventStore;
use Ultra\Projection\Signal\ProjectionSignalHandler;
use Ultra\Signal\BucketRange;
use Ultra\Signal\SignalHint;
use Ultra\Signal\SignalStore;
use Ultra\Signal\SuppressionHandle;
use Ultra\Signal\VisibilitySnapshot;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

#[EventAttribute('signal.course.created')]
final readonly class SignalCourseCreated
{
    public function __construct(
        public string $courseId,
        public string $name,
    ) {}
}

#[Projection(name: 'signal_projection', partitionBy: 'courseId')]
final class SignalProjection
{
    /** @var array<string, string> */
    public array $names = [];

    public int $flushes = 0;

    #[ProjectionHandler]
    public function onCourseCreated(SignalCourseCreated $event): void
    {
        $this->names[$event->courseId] = $event->name;
    }

    #[ProjectionFlush]
    public function flush(): void
    {
        ++$this->flushes;
    }
}

/**
 * @internal
 */
#[CoversClass(ProjectionSignalEventStore::class)]
#[CoversClass(ProjectionSignalHandler::class)]
#[CoversClass(ProjectionRunner::class)]
final class ProjectionSignalIntegrationTest extends TestCase
{
    public function test_signal_event_store_records_one_signal_per_touched_projection_bucket(): void
    {
        $signalStore = new RecordingProjectionSignalStore();
        $projection = new SignalProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new ProjectionSignalEventStore(
            new InMemoryEventStore(),
            new ProjectionCatalog($runtime),
            $signalStore,
        );
        $courseId = self::courseIdInBucketRange(0, 255);
        $bucket = BucketHasher::bucketFor($courseId);

        $eventStore->append([
            self::courseCreatedEvent($courseId, 'Math'),
            self::courseCreatedEvent($courseId, 'Advanced Math'),
        ]);

        self::assertSame([
            ['queueName' => 'signal_projection', 'bucket' => $bucket, 'signalKey' => 'signal_projection:'.$bucket],
        ], $signalStore->signals);
    }

    public function test_signal_handler_processes_current_segment_and_acks_covered_signal_range(): void
    {
        $signalStore = new RecordingProjectionSignalStore();
        $projection = new SignalProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);
        $handler = new ProjectionSignalHandler($runtime, $runner, $signalStore, 'worker-1');
        $courseId = self::courseIdInBucketRange(0, 255);
        $bucket = BucketHasher::bucketFor($courseId);

        $eventStore->append([self::courseCreatedEvent($courseId, 'Math')]);
        $signalStore->signal('signal_projection', $bucket, 'signal_projection:'.$bucket);

        $handler->handleSignalHints([
            new SignalHint(1, 'signal_projection', $bucket, 'signal_projection:'.$bucket),
        ]);

        self::assertSame([$courseId => 'Math'], $projection->names);
        self::assertSame([[0, 256]], $signalStore->ackedRanges);
        self::assertSame([[0, 256]], $signalStore->suppressedRanges);
        self::assertSame([], $signalStore->signals);
        self::assertNull($segments->listSegments('signal_projection')[0]->workerId);
    }

    public function test_signal_handler_uses_current_topology_when_handling_a_hint(): void
    {
        $signalStore = new RecordingProjectionSignalStore();
        $projection = new SignalProjection();
        $runtime = new ProjectionRuntime($projection, new UltraNormalizer());
        $eventStore = new InMemoryEventStore();
        $segments = new InMemoryProjectionSegmentRepository();
        $runner = new ProjectionRunner($eventStore, $segments);
        $handler = new ProjectionSignalHandler($runtime, $runner, $signalStore, 'worker-1');
        $courseId = self::courseIdInBucketRange(128, 255);
        $bucket = BucketHasher::bucketFor($courseId);

        $eventStore->append([self::courseCreatedEvent($courseId, 'Math')]);
        $runner->ensureInitialized($runtime);
        $segments->splitSegment('signal_projection', 0);
        $segments->replaceSegmentsWithMergedSegment('signal_projection', 0, 255, Checkpoint::beginning());

        $handler->handleSignalHints([
            new SignalHint(1, 'signal_projection', $bucket, 'signal_projection:'.$bucket),
        ]);

        self::assertSame([$courseId => 'Math'], $projection->names);
        self::assertSame([[0, 256]], $signalStore->ackedRanges);
        self::assertSame(0, $segments->listSegments('signal_projection')[0]->bucketFrom);
    }

    private static function courseCreatedEvent(string $courseId, string $name): Event
    {
        return new Event(
            eventType: 'signal.course.created',
            payload: ['courseId' => $courseId, 'name' => $name],
            domainIds: ['courseId' => $courseId],
        );
    }

    private static function courseIdInBucketRange(int $from, int $to): string
    {
        for ($i = 0; $i < 10000; ++$i) {
            $courseId = 'signal-course-'.$i;
            $bucket = BucketHasher::bucketFor($courseId);
            if ($bucket >= $from && $bucket <= $to) {
                return $courseId;
            }
        }

        throw new \RuntimeException(sprintf('No course id found for bucket range [%d-%d].', $from, $to));
    }
}

final class RecordingProjectionSignalStore implements SignalStore
{
    /** @var list<array{queueName: string, bucket: int, signalKey: null|string}> */
    public array $signals = [];

    /** @var list<array{0: int, 1: int}> */
    public array $ackedRanges = [];

    /** @var list<array{0: int, 1: int}> */
    public array $suppressedRanges = [];

    public function signal(string $queueName, int $bucket = 0, ?string $signalKey = null, ?DatePoint $visibleAt = null): void
    {
        $this->signals[] = ['queueName' => $queueName, 'bucket' => $bucket, 'signalKey' => $signalKey];
    }

    public function claimHints(array $queueNames, string $owner, int $limit, Duration $deliveryTimeout): array
    {
        throw new \LogicException('Projection signal tests call handlers directly.');
    }

    public function captureVisibilitySnapshot(): VisibilitySnapshot
    {
        return new VisibilitySnapshot('snapshot', DatePoint::create(2026, 1, 1));
    }

    public function ackVisibleInSnapshot(string $queueName, BucketRange $bucketRange, VisibilitySnapshot $snapshot, ?int $limit = null): int
    {
        $this->ackedRanges[] = [$bucketRange->lowerInclusive, $bucketRange->upperExclusive];
        $before = count($this->signals);

        $this->signals = array_values(array_filter(
            $this->signals,
            static fn (array $signal): bool => $signal['queueName'] !== $queueName
                || $signal['bucket'] < $bucketRange->lowerInclusive
                || $signal['bucket'] >= $bucketRange->upperExclusive,
        ));

        return $before - count($this->signals);
    }

    public function suppressDispatch(string $queueName, BucketRange $bucketRange, string $owner, DatePoint $suppressUntil, ?string $domainLeaseRef = null): SuppressionHandle
    {
        $this->suppressedRanges[] = [$bucketRange->lowerInclusive, $bucketRange->upperExclusive];

        return new SuppressionHandle(count($this->suppressedRanges), $queueName, $bucketRange, $owner, $suppressUntil);
    }

    public function extendSuppression(SuppressionHandle $handle, DatePoint $suppressUntil): void {}

    public function releaseSuppression(SuppressionHandle $handle): void {}

    public function cleanupExpired(): void {}
}
