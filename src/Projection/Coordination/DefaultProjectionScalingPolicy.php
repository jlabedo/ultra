<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionDefinition;

final readonly class DefaultProjectionScalingPolicy implements ProjectionScalingPolicyInterface
{
    public function additionalWorkerWakeups(ProjectionDefinition $definition, array $pendingBuckets): int
    {
        return 0;
    }
}
