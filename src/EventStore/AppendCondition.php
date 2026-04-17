<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Loaded query plus the checkpoint used to validate a later append.
 */
readonly class AppendCondition
{
    public function __construct(
        public Checkpoint $checkpoint,
        public StreamQuery $query,
    ) {}
}
