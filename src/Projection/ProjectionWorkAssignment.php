<?php

declare(strict_types=1);

namespace Ultra\Projection;

final readonly class ProjectionWorkAssignment
{
    public function __construct(
        public string $assignmentId,
        public string $projectionId,
        public int $bucketFrom,
        public string $workerId,
    ) {}
}
