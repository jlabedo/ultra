<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
use Ultra\Time\Duration;

interface ProjectionSegmentRepositoryInterface
{
    public function ensureInitialized(string $projectionId): bool;

    public function claimSegment(
        string $projectionId,
        string $workerId,
        Duration $leaseDurationSeconds = new Duration(seconds: 60),
    ): ?ProjectionSegment;

    public function claimSegmentForBucket(
        string $projectionId,
        int $bucket,
        string $workerId,
        Duration $leaseDurationSeconds = new Duration(seconds: 60),
    ): ?ProjectionSegment;

    public function readCurrentState(string $projectionId, int $bucketFrom): ProjectionSegment;

    public function completeSegment(ProjectionSegment $segment, Checkpoint $checkpoint): void;

    public function abandonSegment(ProjectionSegment $segment): void;

    public function renewLease(ProjectionSegment $segment, string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void;

    public function recoverExpiredLeases(string $projectionId): int;

    public function reconcilePendingOperations(string $projectionId): int;

    public function splitSegment(string $projectionId, int $bucketFrom): void;

    public function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void;

    public function requestMerge(string $projectionId, int $bucketFrom, int $bucketTo): void;

    public function finalizeMerge(ProjectionSegment $segment, Checkpoint $checkpoint): void;
}
