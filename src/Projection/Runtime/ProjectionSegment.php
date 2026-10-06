<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;

readonly class ProjectionSegment
{
    public function __construct(
        public string $projectionId,
        public int $bucketFrom,
        public int $bucketTo,
        public Checkpoint $checkpoint,
        public ?string $workerId = null,
    ) {}
}
