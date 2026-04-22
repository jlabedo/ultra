<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionBucketActivityRepositoryInterface
{
    public function bump(string $projectionId, int $bucket): void;

    /** @return list<ProjectionBucketActivity> */
    public function pendingBuckets(string $projectionId, int $limit = 32): array;

    public function acknowledge(string $projectionId, int $bucket, int $counter): void;
}
