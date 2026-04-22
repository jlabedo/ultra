<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionPlan;

interface ProjectionScalingPolicyInterface
{
    /**
     * @param list<ProjectionBucketActivity> $pendingBuckets
     */
    public function additionalWorkerWakeups(ProjectionPlan $plan, array $pendingBuckets): int;
}
