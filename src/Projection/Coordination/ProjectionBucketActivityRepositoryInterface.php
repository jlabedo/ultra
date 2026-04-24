<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

interface ProjectionBucketActivityRepositoryInterface
{
    public function bump(string $projectionId, int $bucket): void;

    /** @return list<ProjectionBucketActivity> */
    public function affectedBuckets(int $limit = 256): array;

    /** @return list<ProjectionBucketActivity> */
    public function pendingBuckets(string $projectionId, int $limit = 32): array;

    /** @return list<ProjectionBucketActivity> */
    public function pendingBucketsForSegment(string $projectionId, int $bucketFrom, int $bucketTo): array;

    /** @param array<int, int> $seenCounters bucket => seen pending counter */
    public function decrementSeenBuckets(string $projectionId, array $seenCounters): int;
}
