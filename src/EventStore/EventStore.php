<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

/**
 * Stores events and exposes optimistic concurrency over stream queries.
 *
 * A successful `load()` returns both the matching events and a `VersionedQuery` that captures the original
 * query plus an opaque token for its materialized snapshot. Passing that token back to `append()` allows
 * implementations to reject concurrent writes that would have changed the queried snapshot in the meantime.
 */
interface EventStore
{
    /**
     * Load events matching the query.
     *
     * Passing a `VersionedQuery` reloads its embedded `StreamQuery` and returns a fresh `VersionedQuery`.
     * `load()` does not validate the previous token; pass it to `append()` for optimistic concurrency.
     */
    public function load(StreamQuery|VersionedQuery $query): LoadResult;

    /**
     * Append events to the store.
     *
     * If `expectedQueries` are provided, the store validates that replaying each exact query still yields a
     * matching snapshot token. If not, a `ConcurrencyException` is thrown and nothing should be considered
     * committed by the caller.
     *
     * @param list<Event> $events
     *
     * @throws ConcurrencyException when any expected query is provided and its snapshot has changed
     */
    public function append(array $events, VersionedQuery ...$expectedQueries): void;
}
