<?php

declare(strict_types=1);

namespace Ultra\Projection\Backfill;

final readonly class ProjectionBackfillJob
{
    public function __construct(
        public string $projectionId,
        public int $bucketFrom,
        public string $workerId,
        public int $limit,
    ) {}
}

