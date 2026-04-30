<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
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
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void;

    public function abandonAssignedSegment(ProjectionSegment $segment, string $workerId): void;

    public function recoverExpiredAssignments(string $projectionId): int;

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

    public function replaceSegmentsWithMergedSegment(string $projectionId, int $bucketFrom, int $bucketTo, Checkpoint $checkpoint): void;

    /** @return list<array{bucketFrom: int, bucketTo: int, workerId: null|string}> */
    public function listSegments(string $projectionId): array;

    /** @return list<array{bucketFrom: int, bucketTo: int, workerId: null|string}> */
    public function availableSegments(string $projectionId): array;
}
