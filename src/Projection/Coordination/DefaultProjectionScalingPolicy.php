<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class DefaultProjectionScalingPolicy implements ProjectionScalingPolicyInterface
{
    public function additionalWorkersRequested(array $affectedBuckets): int
    {
        return 0;
    }
}
