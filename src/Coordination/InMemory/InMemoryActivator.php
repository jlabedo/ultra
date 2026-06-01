<?php

declare(strict_types=1);

namespace Ultra\Coordination\InMemory;

use Ultra\Coordination\Lease\Activator;

final class InMemoryActivator implements Activator
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
