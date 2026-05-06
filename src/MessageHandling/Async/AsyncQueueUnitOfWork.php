<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\Time\Duration;
use Ultra\UnitOfWork\UnitOfWork;

/**
 * Buffers async enqueues so they can be flushed inside the surrounding unit-of-work transaction.
 */
final class AsyncQueueUnitOfWork implements AsyncQueueInterface, UnitOfWork
{
    /**
     * @var list<array{envelope: AsyncEnvelope, options: AsyncEnqueueOptions}>
     */
    private array $pendingEnqueues = [];

    public function __construct(private readonly AsyncQueueInterface $inner) {}

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $options ??= new AsyncEnqueueOptions();
        $messageId = $options->messageId ?? AsyncMessageId::generate();

        $this->pendingEnqueues[] = [
            'envelope' => $envelope,
            'options' => new AsyncEnqueueOptions(
                messageId: $messageId,
                delay: $options->delay,
            ),
        ];

        return $messageId;
    }

    public function reserve(Duration $leaseDuration = new Duration(seconds: 30)): ?AsyncDelivery
    {
        return $this->inner->reserve($leaseDuration);
    }

    public function acknowledge(AsyncDelivery $delivery): void
    {
        $this->inner->acknowledge($delivery);
    }

    public function release(AsyncDelivery $delivery, ?Duration $delay = null, ?\Throwable $reason = null): void
    {
        $this->inner->release($delivery, $delay, $reason);
    }

    public function renew(AsyncDelivery $delivery, Duration $leaseDuration = new Duration(seconds: 30)): void
    {
        $this->inner->renew($delivery, $leaseDuration);
    }

    public function flush(): void
    {
        foreach ($this->pendingEnqueues as $pending) {
            $this->inner->enqueue($pending['envelope'], $pending['options']);
        }
    }

    public function clear(): void
    {
        $this->pendingEnqueues = [];
    }

    public function pendingCount(): int
    {
        return count($this->pendingEnqueues);
    }
}
