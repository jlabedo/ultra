<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionActivatorInterface;

final class InMemoryProjectionActivator implements ProjectionActivatorInterface
{
    private int $workSignals = 0;

    /** @var list<int> */
    private array $workerRequests = [];

    public function signalWorkAvailable(): void
    {
        ++$this->workSignals;
    }

    public function requestAdditionalWorkers(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->workerRequests[] = $count;
    }

    public function workSignals(): int
    {
        return $this->workSignals;
    }

    /** @return list<int> */
    public function workerRequests(): array
    {
        return $this->workerRequests;
    }
}
