<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionScalingPolicyInterface
{
    /**
     * @param list<ProjectionBucketActivity> $affectedBuckets
     */
    public function additionalWorkersRequested(array $affectedBuckets): int;
}
