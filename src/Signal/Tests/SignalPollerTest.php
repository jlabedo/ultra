<?php

declare(strict_types=1);

namespace Ultra\Signal\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Ultra\Signal\BucketRange;
use Ultra\Signal\SignalHandler;
use Ultra\Signal\SignalHint;
use Ultra\Signal\SignalPoller;
use Ultra\Signal\SignalStore;
use Ultra\Signal\SuppressionHandle;
use Ultra\Signal\VisibilitySnapshot;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

#[CoversClass(SignalPoller::class)]
final class SignalPollerTest extends TestCase
{
    public function test_routes_claimed_hints_to_handlers_in_round_robin_order(): void
    {
        $store = new RecordingSignalStore([
            'alpha' => [[new SignalHint(1, 'alpha', 10, 'a')], [new SignalHint(3, 'alpha', 12, 'a3')]],
            'beta' => [[new SignalHint(2, 'beta', 20, 'b')]],
        ]);
        $alpha = new RecordingSignalHandler('alpha');
        $beta = new RecordingSignalHandler('beta');
        $poller = new SignalPoller($store, [$alpha, $beta], perQueueLimit: 1, workerId: 'worker-1');

        self::assertSame(2, $poller->pollOnce());
        self::assertSame(1, $alpha->handledBatches[0][0]->id);
        self::assertSame(2, $beta->handledBatches[0][0]->id);
        self::assertSame(['alpha', 'beta'], $store->claimedQueues);

        self::assertSame(1, $poller->pollOnce());
        self::assertSame(3, $alpha->handledBatches[1][0]->id);
        self::assertSame(['alpha', 'beta', 'beta', 'alpha'], $store->claimedQueues);
    }

    public function test_handler_failure_is_rethrown(): void
    {
        $store = new RecordingSignalStore([
            'alpha' => [[new SignalHint(1, 'alpha', 10, null)]],
        ]);
        $poller = new SignalPoller($store, [new ThrowingSignalHandler('alpha')], workerId: 'worker-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler failed');

        $poller->pollOnce();
    }
}

final class RecordingSignalStore implements SignalStore
{
    /** @var list<string> */
    public array $claimedQueues = [];

    /**
     * @param array<string, list<list<SignalHint>>> $batchesByQueue
     */
    public function __construct(private array $batchesByQueue) {}

    public function signal(string $queueName, int $bucket = 0, ?string $signalKey = null, ?DatePoint $visibleAt = null): void {}

    public function claimHints(array $queueNames, string $owner, int $limit, Duration $deliveryTimeout): array
    {
        Assert::assertCount(1, $queueNames);
        Assert::assertSame('worker-1', $owner);
        Assert::assertGreaterThan(0, $limit);

        $queueName = $queueNames[0];
        $this->claimedQueues[] = $queueName;

        return array_shift($this->batchesByQueue[$queueName]) ?? [];
    }

    public function captureVisibilitySnapshot(): VisibilitySnapshot
    {
        throw new \LogicException('Not used by poller tests.');
    }

    public function ackVisibleInSnapshot(string $queueName, BucketRange $bucketRange, VisibilitySnapshot $snapshot, ?int $limit = null): int
    {
        throw new \LogicException('Poller must not ack signals.');
    }

    public function suppressDispatch(string $queueName, BucketRange $bucketRange, string $owner, DatePoint $suppressUntil, ?string $domainLeaseRef = null): SuppressionHandle
    {
        throw new \LogicException('Not used by poller tests.');
    }

    public function extendSuppression(SuppressionHandle $handle, DatePoint $suppressUntil): void {}

    public function releaseSuppression(SuppressionHandle $handle): void {}

    public function cleanupExpired(): void {}
}

final class RecordingSignalHandler implements SignalHandler
{
    /** @var list<list<SignalHint>> */
    public array $handledBatches = [];

    public function __construct(private readonly string $queueName) {}

    public function queueName(): string
    {
        return $this->queueName;
    }

    public function handleSignalHints(array $hints): void
    {
        $this->handledBatches[] = $hints;
    }
}

final class ThrowingSignalHandler implements SignalHandler
{
    public function __construct(private readonly string $queueName) {}

    public function queueName(): string
    {
        return $this->queueName;
    }

    public function handleSignalHints(array $hints): void
    {
        throw new \RuntimeException('handler failed');
    }
}
