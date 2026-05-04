<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class ProjectionCoordinatorResult
{
    public function __construct(
        public int $processedEvents,
        public int $acknowledgedBuckets,
    ) {}

    public function wasProductive(): bool
    {
        return $this->processedEvents > 0 || $this->acknowledgedBuckets > 0;
    }
}
