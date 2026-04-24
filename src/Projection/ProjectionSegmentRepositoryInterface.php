<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\Time\Duration;

interface ProjectionSegmentRepositoryInterface
{
    public function ensureInitialized(string $projectionId): bool;

    public function assignSegment(
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): ?ProjectionWorkAssignment;

    public function claimAssignedSegment(
        ProjectionWorkAssignment $assignment,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): ?ProjectionSegment;

    public function completeAssignedBatch(
        ProjectionSegment $segment,
        \Ultra\EventStore\Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void;

    public function abandonAssignedSegment(ProjectionSegment $segment, string $workerId): void;

    public function recoverExpiredAssignments(string $projectionId): int;

    public function reconcilePendingOperations(string $projectionId): int;

    public function splitSegment(string $projectionId, int $bucketFrom): void;

    /**
     * @return list<array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string}>
     */
    public function listSegments(string $projectionId): array;

    /**
     * @return list<array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string}>
     */
    public function availableSegments(string $projectionId): array;

    public function requestMerge(string $projectionId, int $bucketFrom, int $bucketTo): void;
}
