<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionActivatorInterface;

final class InMemoryProjectionActivator implements ProjectionActivatorInterface
{
    private int $workSignals = 0;

    public function signalWorkAvailable(): void
    {
        ++$this->workSignals;
    }

    public function workSignals(): int
    {
        return $this->workSignals;
    }
}
