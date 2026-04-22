<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class DefaultProjectionScalingPolicy implements ProjectionScalingPolicyInterface
{
    public function additionalWorkerWakeups(array $affectedBuckets): int
    {
        return 0;
    }
}
