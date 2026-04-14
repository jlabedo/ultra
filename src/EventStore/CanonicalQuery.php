<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Internal representation of a validated `StreamQuery`.
 *
 * It preserves the original query for callers while exposing the resolved branch metadata and lock keys that
 * back concurrency checks.
 */
readonly class CanonicalQuery
{
    /**
     * @param list<CanonicalQueryBranch> $branches
     */
    public function __construct(
        public StreamQuery $query,
        public array $branches,
    ) {}

    /**
     * @return list<string>
     */
    public function allKeys(): array
    {
        $keys = [];
        foreach ($this->branches as $branch) {
            foreach ($branch->keys as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }
}
