<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionDefinition;

interface ProjectionScalingPolicyInterface
{
    /**
     * @param list<ProjectionBucketActivity> $pendingBuckets
     */
    public function additionalWorkerWakeups(ProjectionDefinition $definition, array $pendingBuckets): int;
}
