<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\EventStore\Checkpoint;

final readonly class SegmentState
{
    public function __construct(
        public string $projectionId,
        public int $bucketFrom,
        public int $bucketTo,
        public string $status,
        public ?string $pendingOperation,
        public Checkpoint $checkpoint,
        public bool $wasCreated,
    ) {}

    public function segmentCount(): int
    {
        return intdiv(256, $this->bucketTo - $this->bucketFrom + 1);
    }

    public function segmentIndex(): int
    {
        return intdiv($this->bucketFrom, $this->bucketTo - $this->bucketFrom + 1);
    }
}
