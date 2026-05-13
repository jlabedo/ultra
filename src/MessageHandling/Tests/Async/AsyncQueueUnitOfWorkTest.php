<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Async;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\Async\AsyncQueueUnitOfWork;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\MessageContext;

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
        $queue->flush();

        self::assertSame(1, $inner->count());

        $delivery = $inner->reserve();
        self::assertNotNull($delivery);
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
