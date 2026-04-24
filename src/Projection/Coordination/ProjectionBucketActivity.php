<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class ProjectionBucketActivity
{
    public function __construct(
        public string $projectionId,
        public int $bucket,
        public int $counter,
        public int $acknowledgedCounter = 0,
    ) {}

    public function isPending(): bool
    {
        return $this->counter > 0;
    }
}
