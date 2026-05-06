<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Async;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\Async\AsyncDelivery;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncEnqueueOptions;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\Async\AsyncMessageId;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\MessageContext;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

#[CoversClass(InMemoryQueue::class)]
final class InMemoryQueueTest extends TestCase
{
    public function test_reserve_holds_message_until_acknowledged(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $queue = new InMemoryQueue($clock);

        $queue->enqueue($this->envelope('first'));

        $delivery = $queue->reserve(Duration::seconds(30));

        self::assertInstanceOf(AsyncDelivery::class, $delivery);
        self::assertNull($queue->reserve(Duration::seconds(30)));
        self::assertSame(1, $queue->count());

        $queue->acknowledge($delivery);

        self::assertTrue($queue->isEmpty());
    }

    public function test_expired_lease_can_be_reserved_again_and_stale_ack_does_not_delete_it(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $queue = new InMemoryQueue($clock);

        $queue->enqueue($this->envelope('first'));

        $stale = $queue->reserve(Duration::seconds(10));
        self::assertInstanceOf(AsyncDelivery::class, $stale);

        $clock->sleep(Duration::seconds(11));

        $current = $queue->reserve(Duration::seconds(10));
        self::assertInstanceOf(AsyncDelivery::class, $current);
        self::assertNotSame($stale->leaseId, $current->leaseId);

        $queue->acknowledge($stale);
        self::assertSame(1, $queue->count());

        $queue->acknowledge($current);
        self::assertTrue($queue->isEmpty());
    }

    public function test_delayed_message_is_not_reserved_until_available(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $queue = new InMemoryQueue($clock);

        $queue->enqueue($this->envelope('first'), new AsyncEnqueueOptions(delay: Duration::seconds(10)));

        self::assertNull($queue->reserve());

        $clock->sleep(Duration::seconds(10));

        self::assertInstanceOf(AsyncDelivery::class, $queue->reserve());
    }

    private function envelope(string $value): AsyncEnvelope
    {
        return new AsyncEnvelope(
            new InMemoryQueueTestMessage($value),
            MessageContext::fromArray([]),
            AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.route'),
        );
    }
}

final readonly class InMemoryQueueTestMessage
{
    public function __construct(public string $value) {}
}
