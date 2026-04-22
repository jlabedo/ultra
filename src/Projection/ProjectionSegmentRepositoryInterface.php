<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;

interface ProjectionSegmentRepositoryInterface
{
    public function ensureInitialized(string $projectionId): bool;

    public function claimSegment(
        string $projectionId,
        string $workerId,
        int $leaseDurationSeconds = 60,
    ): ?ProjectionSegment;

    public function claimSegmentForBucket(
        string $projectionId,
        int $bucket,
        string $workerId,
        int $leaseDurationSeconds = 60,
    ): ?ProjectionSegment;

    public function readCurrentState(string $projectionId, int $bucketFrom): ProjectionSegment;

    public function releaseSegment(ProjectionSegment $segment, Checkpoint $checkpoint): void;

    public function renewLease(ProjectionSegment $segment, string $workerId, int $leaseDurationSeconds = 60): void;

    public function recoverExpiredLeases(string $projectionId): int;

    public function splitSegment(string $projectionId, int $bucketFrom): void;

    public function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void;

    public function requestMerge(string $projectionId, int $bucketFrom, int $bucketTo): void;

    public function finalizeMerge(ProjectionSegment $segment, Checkpoint $checkpoint): void;
}
