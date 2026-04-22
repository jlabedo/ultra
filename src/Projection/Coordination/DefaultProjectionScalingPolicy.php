<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionPlan;

final readonly class DefaultProjectionScalingPolicy implements ProjectionScalingPolicyInterface
{
    public function additionalWorkerWakeups(ProjectionPlan $plan, array $pendingBuckets): int
    {
        return 0;
    }
}
