<?php

declare(strict_types=1);

namespace Ultra\Mediator_Example\OrderProcessing\Infrastructure;

final class CleanupTracker
{
    public int $calls = 0;

    public function cleanup(): void
    {
        $this->calls++;
    }
}
