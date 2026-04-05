<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

final class InMemoryQueue implements AsyncQueueInterface
{
    /** @var list<AsyncEnvelope> */
    private array $queue = [];

    public function enqueue(AsyncEnvelope $envelope): void
    {
        $this->queue[] = $envelope;
    }

    public function dequeue(): ?AsyncEnvelope
    {
        if ([] === $this->queue) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function isEmpty(): bool
    {
        return [] === $this->queue;
    }

    public function count(): int
    {
        return count($this->queue);
    }
}
