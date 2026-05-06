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
use Ultra\MessageHandling\Async\AsyncQueueUnitOfWork;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\MessageContext;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

#[CoversClass(AsyncQueueUnitOfWork::class)]
final class AsyncQueueUnitOfWorkTest extends TestCase
{
    public function test_enqueue_is_buffered_until_flush(): void
    {
        $inner = new InMemoryQueue();
        $queue = new AsyncQueueUnitOfWork($inner);

        $messageId = $queue->enqueue($this->envelope('first'));

        self::assertSame(1, $queue->pendingCount());
        self::assertSame(0, $inner->count());
        self::assertNull($queue->reserve());

        $queue->flush();

        self::assertSame(1, $inner->count());

        $delivery = $queue->reserve();
        self::assertInstanceOf(AsyncDelivery::class, $delivery);
        self::assertSame($messageId->value, $delivery->messageId->value);
    }

    public function test_clear_drops_buffered_enqueues(): void
    {
        $inner = new InMemoryQueue();
        $queue = new AsyncQueueUnitOfWork($inner);

        $queue->enqueue($this->envelope('first'));
        $queue->clear();
        $queue->flush();

        self::assertSame(0, $queue->pendingCount());
        self::assertSame(0, $inner->count());
    }

    public function test_delivery_operations_delegate_immediately(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $inner = new InMemoryQueue($clock);
        $queue = new AsyncQueueUnitOfWork($inner);

        $inner->enqueue(
            $this->envelope('first'),
            new AsyncEnqueueOptions(messageId: new AsyncMessageId('message-1')),
        );

        $delivery = $queue->reserve(Duration::seconds(10));
        self::assertInstanceOf(AsyncDelivery::class, $delivery);

        $queue->renew($delivery, Duration::seconds(20));
        $clock->sleep(Duration::seconds(11));
        self::assertNull($queue->reserve());

        $queue->release($delivery, Duration::seconds(5));
        self::assertNull($queue->reserve());

        $clock->sleep(Duration::seconds(5));
        $redelivery = $queue->reserve();
        self::assertInstanceOf(AsyncDelivery::class, $redelivery);

        $queue->acknowledge($redelivery);
        self::assertTrue($inner->isEmpty());
    }

    private function envelope(string $value): AsyncEnvelope
    {
        return new AsyncEnvelope(
            new AsyncQueueUnitOfWorkTestMessage($value),
            MessageContext::fromArray([]),
            AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.route'),
        );
    }
}

final readonly class AsyncQueueUnitOfWorkTestMessage
{
    public function __construct(public string $value) {}
}
