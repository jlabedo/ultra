<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
use Ultra\Time\Duration;

interface ProjectionTopologyStoreInterface
{
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
}

