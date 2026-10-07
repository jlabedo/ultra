<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
use Ultra\Time\Duration;

interface ProjectionSegmentRepositoryInterface
{
    public function ensureInitialized(string $projectionId): bool;

    public function deleteProjection(string $projectionId): void;

    public function claimSegment(
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): ?ProjectionSegment;

    public function completeClaimedSegment(
        ProjectionSegment $segment,
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void;

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
