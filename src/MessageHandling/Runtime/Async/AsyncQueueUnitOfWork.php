<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Buffers async enqueues so they can be flushed inside the surrounding unit-of-work transaction.
 */
final class AsyncQueueUnitOfWork implements AsyncMessageSender, UnitOfWork
{
    /**
     * @var list<array{envelope: AsyncEnvelope, options: AsyncEnqueueOptions}>
     */
    private array $pendingEnqueues = [];

    public function __construct(
        private readonly AsyncMessageSender $inner,
    ) {}

    public function enqueue(AsyncEnvelope $envelope, ?AsyncEnqueueOptions $options = null): AsyncMessageId
    {
        $options ??= new AsyncEnqueueOptions();
        $messageId = $options->messageId ?? AsyncMessageId::generate();

        $this->pendingEnqueues[] = [
            'envelope' => $envelope,
            'options' => new AsyncEnqueueOptions(
                messageId: $messageId,
                delay: $options->delay,
                availableAt: $options->availableAt,
                key: $options->key,
                replace: $options->replace,
            ),
        ];

        return $messageId;
    }

    public function flush(): void
    {
        foreach ($this->pendingEnqueues as $pending) {
            $this->inner->enqueue($pending['envelope'], $pending['options']);
        }

        $this->pendingEnqueues = [];
    }

    public function hasPendingChanges(): bool
    {
        return [] !== $this->pendingEnqueues;
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
