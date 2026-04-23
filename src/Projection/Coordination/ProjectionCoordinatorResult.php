<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

final readonly class ProjectionCoordinatorResult
{
    public function __construct(
        public bool $acquiredLease,
        public int $processedEvents,
        public int $acknowledgedBuckets,
        public int $requestedWorkerActivations,
    ) {}
}
