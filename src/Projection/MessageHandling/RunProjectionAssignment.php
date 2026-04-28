<?php

declare(strict_types=1);

namespace Ultra\Projection\MessageHandling;

use Ultra\Projection\ProjectionWorkAssignment;

final readonly class RunProjectionAssignment
{
    public function __construct(
        public string $assignmentId,
        public string $projectionId,
        public int $bucketFrom,
        public string $workerId,
    ) {}

    public static function fromAssignment(ProjectionWorkAssignment $assignment): self
    {
        return new self(
            $assignment->assignmentId,
            $assignment->projectionId,
            $assignment->bucketFrom,
            $assignment->workerId,
        );
    }

    public function toAssignment(): ProjectionWorkAssignment
    {
        return new ProjectionWorkAssignment(
            $this->assignmentId,
            $this->projectionId,
            $this->bucketFrom,
            $this->workerId,
        );
    }
}
