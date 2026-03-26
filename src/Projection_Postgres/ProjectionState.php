<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

final readonly class ProjectionState
{
    public function __construct(
        public string $projectionName,
        public int $projectionVersion,
        public string $lane,
        public int $lastEventId,
        public bool $wasCreated,
    ) {}
}
