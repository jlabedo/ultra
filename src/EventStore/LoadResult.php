<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Result of loading a stream query.
 *
 * The returned events are the materialized snapshot used by the caller, while `versionedQuery` can be passed
 * back to `load()` to reload the same query or to `append()` for optimistic concurrency.
 */
readonly class LoadResult
{
    /**
     * @param list<Event>     $events         events matching the query, ordered by stream order ascending
     * @param VersionedQuery  $versionedQuery query plus opaque optimistic-concurrency token
     */
    public function __construct(
        public array $events,
        public VersionedQuery $versionedQuery,
    ) {}
}
