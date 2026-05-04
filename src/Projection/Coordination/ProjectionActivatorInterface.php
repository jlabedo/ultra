<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionActivatorInterface
{
    public function signalWorkAvailable(): void;
}
