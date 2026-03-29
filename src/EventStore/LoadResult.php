<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Result of loading a stream query.
 *
 * The returned events are the materialized snapshot used by the caller, while `queryVersion` is the
 * optimistic-concurrency token that must be passed back to `append()` if the caller wants a replayed
 * version check.
 */
readonly class LoadResult
{
    /**
     * @param list<StoredEvent> $events       events matching the query, ordered by eventId ascending
     * @param QueryVersion      $queryVersion optimistic concurrency token for the loaded query
     */
    public function __construct(
        public array $events,
        public QueryVersion $queryVersion,
    ) {}
}
