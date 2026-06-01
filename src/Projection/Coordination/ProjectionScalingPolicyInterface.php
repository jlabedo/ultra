<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Coordination\Tracking\PendingKey;

interface ProjectionScalingPolicyInterface
{
    /**
     * @param list<PendingKey> $affectedBuckets
     */
    public function additionalWorkersRequested(array $affectedBuckets): int;
}
