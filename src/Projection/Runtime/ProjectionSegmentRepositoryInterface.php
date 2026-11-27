<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\EventStore\Checkpoint;
use Ultra\Time\Duration;

interface ProjectionSegmentRepositoryInterface
{
    public function ensureInitialized(string $projectionId): bool;

    public function deleteProjection(string $projectionId): void;

    /** Claim an available segment or renew its claim. With $requireOwned, it must already belong to $workerId. */
    public function claimSegment(
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
        bool $requireOwned = false,
    ): ?ProjectionSegment;

    /** Claim the full bucket range if its segment exists and is free or expired. */
    public function claimFullRangeSegment(
        string $projectionId,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): ?ProjectionSegment;

    /**
     * Advance the checkpoint and optionally drain the observed activity after catch-up.
     * Returns the number of reduced counters, or null if the claim was lost.
     *
     * @param array<int, int> $seenCounters
     */
    public function completeClaimedSegment(
        ProjectionSegment $segment,
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        Duration $leaseDuration = new Duration(seconds: 60),
        ?DirtyKeyTracker $activity = null,
        array $seenCounters = [],
    ): ?int;

    public function releaseClaimedSegment(ProjectionSegment $segment, string $workerId): void;

    public function recoverExpiredClaims(string $projectionId): int;

    public function splitSegment(string $projectionId, int $bucketFrom): void;

    /**
     * @return list<ProjectionSegment>
     */
    public function takeSegmentsForRange(
        string $projectionId,
        int $bucketFrom,
        int $bucketTo,
        string $ownerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): array;

    public function replaceSegmentsWithMergedSegment(
        string $projectionId,
        int $bucketFrom,
        int $bucketTo,
        Checkpoint $checkpoint,
    ): void;

    /** @return list<ProjectionSegment> */
    public function listSegments(string $projectionId): array;

    /** @return list<ProjectionSegment> */
    public function availableSegments(string $projectionId): array;
}
