<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests\Async;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\MessageContext;

#[CoversClass(QueueWorker::class)]
final class QueueWorkerTest extends TestCase
{
    public function test_releases_delivery_when_handler_lookup_fails(): void
    {
        $queue = new InMemoryQueue();
        $queue->enqueue($this->envelope('first'));
        $worker = new QueueWorker($queue, new FailingQueueWorkerTestContainer());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler not found.');

        try {
            $worker->processOne();
        } finally {
            self::assertNotNull($queue->reserve());
        }
    }

    private function envelope(string $value): AsyncEnvelope
    {
        return new AsyncEnvelope(
            new QueueWorkerTestMessage($value),
            MessageContext::fromArray([]),
            AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, 'test.route'),
        );
    }
}

final readonly class QueueWorkerTestMessage
{
    public function __construct(public string $value) {}
}

final class FailingQueueWorkerTestContainer implements ContainerInterface
{
    public function get(string $id): never
    {
        throw new \RuntimeException('Handler not found.');
    }

    public function has(string $id): bool
    {
        return false;
    }
}
