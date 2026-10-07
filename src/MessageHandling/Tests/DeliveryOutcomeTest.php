<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Ultra\MessageHandling\Async\AsyncDelivery;
use Ultra\MessageHandling\Async\AsyncEnvelope;
use Ultra\MessageHandling\Async\AsyncHeaderNames;
use Ultra\MessageHandling\Async\AsyncHeaders;
use Ultra\MessageHandling\Async\AsyncMessageReceiver;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Handler\MessageHandlerInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\Tests\Support\QueueTestCase;
use Ultra\Time\Duration;

#[CoversNamespace('Ultra\\MessageHandling')]
final class DeliveryOutcomeTest extends QueueTestCase
{
    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        yield 'missing route' => ['missing route'];
        yield 'empty route' => ['empty route'];
        yield 'handler lookup' => ['lookup'];
        yield 'handler execution' => ['handler'];
        yield 'acknowledgement' => ['acknowledge'];
    }

    #[DataProvider('failures')]
    public function test_failures_release_the_delivery_and_stop_drain_before_the_next_message(string $stage): void
    {
        $f = $this->fixture();
        $failure = new \RuntimeException('temporary failure');
        $first = $this->envelope('first');
        if ('missing route' === $stage || 'empty route' === $stage) {
            $first = new AsyncEnvelope(
                $first->message,
                $first->messageContext,
                'missing route' === $stage
                    ? AsyncHeaders::empty()
                    : AsyncHeaders::empty()->with(AsyncHeaderNames::ROUTE, ''),
            );
        }
        $f->queue->enqueue($first);
        $f->queue->enqueue($this->envelope('second'));
        $handler = new RecordingDeliveryHandler('handler' === $stage ? $failure : null);
        $container = new DeliveryContainer($handler, 'lookup' === $stage ? $failure : null);
        $receiver = new AcknowledgementReceiver($f->queue, 'acknowledge' === $stage ? $failure : null);
        $worker = new QueueWorker($receiver, $container);

        try {
            $worker->drain();
            static::fail('Expected delivery failure.');
        } catch (\Throwable $caught) {
            if ('missing route' === $stage || 'empty route' === $stage) {
                static::assertInstanceOf(\LogicException::class, $caught);
                static::assertStringContainsString('route', $caught->getMessage());
            } else {
                static::assertSame($failure, $caught);
                static::assertSame($failure, $receiver->reason);
            }
        }
        static::assertSame(2, $f->count());
        static::assertSame(in_array($stage, ['handler', 'acknowledge'], true) ? ['first'] : [], $handler->messages);
        $retry = $f->queue->reserve();
        static::assertNotNull($retry);
        static::assertSame('first', $retry->envelope->message->value);
        static::assertSame(2, $retry->attempts);
    }
}

final class RecordingDeliveryHandler implements MessageHandlerInterface
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(
        private ?\Throwable $failure,
    ) {}

    public function __invoke(object $message, MessageContext $context): mixed
    {
        $this->messages[] = $message->value;
        if (null !== $this->failure) {
            $failure = $this->failure;
            $this->failure = null;
            throw $failure;
        }
        return null;
    }
}

final class DeliveryContainer implements ContainerInterface
{
    public function __construct(
        private MessageHandlerInterface $handler,
        private ?\Throwable $failure,
    ) {}

    public function get(string $id): MessageHandlerInterface
    {
        if (null !== $this->failure) {
            $failure = $this->failure;
            $this->failure = null;
            throw $failure;
        }
        return $this->handler;
    }

    public function has(string $id): bool
    {
        return null === $this->failure;
    }
}

final class AcknowledgementReceiver implements AsyncMessageReceiver
{
    public ?\Throwable $reason = null;

    public function __construct(
        private InMemoryQueue $queue,
        private ?\Throwable $failure,
    ) {}

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery
    {
        return $this->queue->reserve($leaseDuration);
    }

    public function acknowledge(AsyncDelivery $delivery): void
    {
        if (null !== $this->failure) {
            $failure = $this->failure;
            $this->failure = null;
            throw $failure;
        }
        $this->queue->acknowledge($delivery);
    }

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void
    {
        $this->reason = $reason;
        $this->queue->release($delivery, $delay, $reason);
    }

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void
    {
        $this->queue->renew($delivery, $leaseDuration);
    }
}
