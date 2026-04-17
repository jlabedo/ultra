<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\EventStore\Checkpoint;

final readonly class ProjectionState
{
    public function __construct(
        public string $projectionName,
        public int $projectionVersion,
        public string $lane,
        public Checkpoint $checkpoint,
        public bool $wasCreated,
    ) {}
}
