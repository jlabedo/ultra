<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionWakeQueueInterface
{
    public function enqueue(object $message): void;

    public function dequeue(): ?object;
}
