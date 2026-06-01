<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Coordination\Lease\Activator;

/**
 * Projection-topology-aware activator: the generic "work is available" signal
 * plus the ability to target a specific worker/segment.
 */
interface ProjectionActivatorInterface extends Activator
{
    public function activateWorker(string $projectionId, int $bucketFrom, string $workerId): void;
}
