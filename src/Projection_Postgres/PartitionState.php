<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

final readonly class PartitionState
{
    public function __construct(
        public string $partitionKey,
        public int $lastEventId,
        public bool $wasCreated = false,
    ) {}
}
