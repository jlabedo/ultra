<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionWakeQueueInterface;

final class InMemoryProjectionWakeQueue implements ProjectionWakeQueueInterface
{
    /** @var list<object> */
    private array $messages = [];

    public function enqueue(object $message): void
    {
        $this->messages[] = $message;
    }

    public function dequeue(): ?object
    {
        return array_shift($this->messages);
    }
}
