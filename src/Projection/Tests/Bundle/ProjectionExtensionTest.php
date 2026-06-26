<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\Events\Attribute\Event;
use Ultra\EventStore\Event as StoredEvent;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Bundle\ProjectionExtension;
use Ultra\Projection\Coordination\ProjectionActivityEventStore;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\Signal\ProjectionSignalEventStore;
use Ultra\Projection\Signal\ProjectionSignalHandler;
use Ultra\Projection\Tests\Support\ProjectionFunctionalTestCase;
use Ultra\Signal\BucketRange;
use Ultra\Signal\SignalHint;
use Ultra\Signal\SignalStore;
use Ultra\Signal\SuppressionHandle;
use Ultra\Signal\VisibilitySnapshot;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

#[Event('projection.stubbed')]
final readonly class StubProjectionEvent {}

#[Projection(name: 'stub_projection', partitionBy: 'id')]
final class StubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[Projection(name: 'other_stub_projection', partitionBy: 'id')]
final class OtherStubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

#[Projection(name: 'third_stub_projection', partitionBy: 'id')]
final class ThirdStubProjection
{
    #[ProjectionHandler]
    public function onSomething(StubProjectionEvent $event): void {}
}

/**
 * @internal
 */
#[CoversClass(ProjectionExtension::class)]
#[CoversClass(ProjectionActivityEventStore::class)]
#[CoversClass(ProjectionActivityRecorder::class)]
#[CoversClass(ProjectionSignalEventStore::class)]
final class ProjectionExtensionTest extends ProjectionFunctionalTestCase
{
    public function test_registers_empty_catalog_when_no_projections_are_discovered(): void
    {
        $catalog = $this->projectionUltraLight()
            ->boot()
            ->projectionCatalog()
        ;

        self::assertInstanceOf(ProjectionCatalog::class, $catalog);
        self::assertSame([], $catalog->definitions());
        self::assertSame([], $catalog->runtimes());
    }

    public function test_wraps_discovered_projections_in_runtimes(): void
    {
        $catalog = $this->projectionUltraLight()
            ->withClass(StubProjection::class)
            ->boot()
            ->projectionCatalog()
        ;

        self::assertInstanceOf(ProjectionCatalog::class, $catalog);
        self::assertCount(1, $catalog->runtimes());
        self::assertContainsOnlyInstancesOf(ProjectionRuntime::class, $catalog->runtimes());
        self::assertSame('stub_projection', $catalog->runtime('stub_projection')->projectionId());
        self::assertSame(['projection.stubbed'], $catalog->definition('stub_projection')->handledEventTypes());
    }

    public function test_catalog_receives_one_runtime_per_discovered_projection(): void
    {
        $catalog = $this->projectionUltraLight()
            ->withClass(StubProjection::class, OtherStubProjection::class, ThirdStubProjection::class)
            ->boot()
            ->projectionCatalog()
        ;

        self::assertInstanceOf(ProjectionCatalog::class, $catalog);
        self::assertSame([
            'stub_projection',
            'other_stub_projection',
            'third_stub_projection',
        ], array_map(
            static fn (ProjectionRuntime $runtime): string => $runtime->projectionId(),
            $catalog->runtimes(),
        ));
    }

    public function test_decorated_event_store_records_projection_activity_after_appending_events(): void
    {
        $container = $this->projectionUltraLight()
            ->withClass(StubProjection::class)
            ->boot()
        ;

        $eventStore = $container->eventStore();
        $activity = $container->projectionActivity();
        $activator = $container->projectionActivator();

        self::assertInstanceOf(EventStore::class, $eventStore);
        self::assertInstanceOf(InMemoryProjectionActivator::class, $activator);

        $eventStore->append([
            new StoredEvent(
                eventType: 'projection.stubbed',
                payload: [],
                domainIds: ['id' => 'stub-1'],
            ),
            new StoredEvent(
                eventType: 'projection.stubbed',
                payload: [],
                domainIds: ['id' => 'stub-1'],
            ),
        ]);

        $pending = $activity->pendingInSegment('stub_projection', 0, 255);

        self::assertCount(1, $pending);
        self::assertSame(1, $pending[0]->counter);
        self::assertSame(1, $activator->workSignals());
    }

    public function test_uses_signal_store_for_projection_wakeups_when_available(): void
    {
        $signalStore = new BundleRecordingSignalStore();
        $container = $this->projectionUltraLight()
            ->withService($signalStore, SignalStore::class)
            ->withClass(StubProjection::class)
            ->boot()
        ;

        $eventStore = $container->eventStore();
        $activity = $container->projectionActivity();
        $handlers = $container->projectionSignalHandlers();
        $poller = $container->signalPoller();

        self::assertInstanceOf(ProjectionSignalEventStore::class, $eventStore);
        self::assertContainsOnlyInstancesOf(ProjectionSignalHandler::class, $handlers);
        self::assertCount(1, $handlers);

        $eventStore->append([
            new StoredEvent(
                eventType: 'projection.stubbed',
                payload: [],
                domainIds: ['id' => 'stub-1'],
            ),
        ]);

        self::assertCount(1, $signalStore->signals);
        self::assertSame('stub_projection', $signalStore->signals[0]['queueName']);
        self::assertSame([], $activity->pendingInSegment('stub_projection', 0, 255));

        self::assertSame(1, $poller->pollOnce());
        self::assertSame([], $signalStore->signals);
    }
}

final class BundleRecordingSignalStore implements SignalStore
{
    /** @var list<array{id: int, queueName: string, bucket: int, signalKey: null|string}> */
    public array $signals = [];

    private int $nextId = 1;

    public function signal(string $queueName, int $bucket = 0, ?string $signalKey = null, ?DatePoint $visibleAt = null): void
    {
        $this->signals[] = ['id' => $this->nextId++, 'queueName' => $queueName, 'bucket' => $bucket, 'signalKey' => $signalKey];
    }

    public function claimHints(array $queueNames, string $owner, int $limit, Duration $deliveryTimeout): array
    {
        $hints = [];

        foreach ($this->signals as $signal) {
            if (!in_array($signal['queueName'], $queueNames, true)) {
                continue;
            }

            $hints[] = new SignalHint($signal['id'], $signal['queueName'], $signal['bucket'], $signal['signalKey']);
            if (count($hints) >= $limit) {
                break;
            }
        }

        return $hints;
    }

    public function captureVisibilitySnapshot(): VisibilitySnapshot
    {
        return new VisibilitySnapshot('snapshot', DatePoint::create(2026, 1, 1));
    }

    public function ackVisibleInSnapshot(string $queueName, BucketRange $bucketRange, VisibilitySnapshot $snapshot, ?int $limit = null): int
    {
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
        return new SuppressionHandle(1, $queueName, $bucketRange, $owner, $suppressUntil);
    }

    public function extendSuppression(SuppressionHandle $handle, DatePoint $suppressUntil): void {}

    public function releaseSuppression(SuppressionHandle $handle): void {}

    public function cleanupExpired(): void {}
}
