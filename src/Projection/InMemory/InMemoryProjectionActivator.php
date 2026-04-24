<?php

declare(strict_types=1);

namespace Ultra\Projection\InMemory;

use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\ProjectionWorkAssignment;

final class InMemoryProjectionActivator implements ProjectionActivatorInterface
{
    private int $workSignals = 0;

    /** @var list<ProjectionWorkAssignment> */
    private array $workerAssignments = [];

    public function signalWorkAvailable(): void
    {
        ++$this->workSignals;
    }

    public function activateWorker(ProjectionWorkAssignment $assignment): void
    {
        $this->workerAssignments[] = $assignment;
    }

    public function workSignals(): int
    {
        return $this->workSignals;
    }

    /** @return list<ProjectionWorkAssignment> */
    public function workerAssignments(): array
    {
        return $this->workerAssignments;
    }
}
