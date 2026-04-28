<?php

declare(strict_types=1);

namespace Ultra\Projection\MessageHandling;

use Ultra\MessageHandling\MediatorInterface;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\ProjectionWorkAssignment;

final readonly class MessageHandlingProjectionActivator implements ProjectionActivatorInterface
{
    public function __construct(
        private MediatorInterface $mediator,
        private string $workerIdPrefix = 'projection',
    ) {}

    public function signalWorkAvailable(): void
    {
        $this->mediator->send(new WakeProjectionCoordinator($this->workerId()));
    }

    public function activateWorker(ProjectionWorkAssignment $assignment): void
    {
        $this->mediator->send(RunProjectionAssignment::fromAssignment($assignment));
    }

    private function workerId(): string
    {
        return sprintf(
            '%s-%s-%s',
            $this->workerIdPrefix,
            gethostname() ?: 'host',
            getmypid(),
        );
    }
}
