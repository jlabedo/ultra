<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class Metrics
{
    public int $success = 0;
    public int $failure = 0;

    public function recordSuccess(): void
    {
        $this->success++;
    }

    public function recordFailure(): void
    {
        $this->failure++;
    }
}
