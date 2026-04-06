<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Async;

interface AsyncQueueInterface
{
    public function enqueue(AsyncEnvelope $envelope): void;

    public function dequeue(): ?AsyncEnvelope;
}
