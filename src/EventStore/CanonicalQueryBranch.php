<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * One validated branch of a canonical query.
 *
 * A branch keeps the original domain identifiers plus the canonical query-stream keys generated for each
 * requested event type. Those keys remain the units used for optimistic concurrency.
 */
readonly class CanonicalQueryBranch
{
    /**
     * @param array<string, string> $domainIdentifiers
     * @param list<string>          $eventTypes
     * @param list<string>          $keys
     */
    public function __construct(
        public array $domainIdentifiers,
        public array $eventTypes,
        public array $keys,
    ) {}
}
