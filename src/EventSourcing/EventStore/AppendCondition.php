<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Loaded non-global query plus the checkpoint used to validate a later append.
 */
readonly class AppendCondition
{
    public function __construct(
        public Checkpoint $checkpoint,
        public StreamQuery $query,
    ) {
        if ($query->hasGlobalBranch()) {
            throw new \InvalidArgumentException('Append conditions cannot use global stream queries.');
        }
    }
}
