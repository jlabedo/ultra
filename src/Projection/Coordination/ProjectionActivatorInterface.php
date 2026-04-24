<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionWorkAssignment;

interface ProjectionActivatorInterface
{
    public function signalWorkAvailable(): void;

    public function activateWorker(ProjectionWorkAssignment $assignment): void;
}
