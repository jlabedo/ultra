<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

/**
 * Stores events and exposes optimistic concurrency over typed canonical query streams.
 *
 * A successful `load()` returns both the matching events and a `QueryVersion` token that captures the
 * original query plus the version of its materialized result. Passing that token back to `append()` allows
 * implementations to reject concurrent writes that would have changed the queried snapshot in the meantime.
 */
interface EventStore
{
    /**
     * Load events matching the query.
     *
     * Implementations may reject queries they cannot represent safely for optimistic concurrency, for
     * example untyped branches or non-canonical identifier pairs.
     */
    public function load(StreamQuery $query): LoadResult;

    /**
     * Append events to the store.
     *
     * If `expectedVersion` is provided, the store validates that replaying that exact query still yields the
     * same version. If not, a `ConcurrencyException` is thrown and nothing should be considered committed by
     * the caller.
     *
     * @param list<Event> $events
     *
     * @throws ConcurrencyException when expectedVersion is provided and the query snapshot has changed
     */
    public function append(array $events, ?QueryVersion $expectedVersion = null): void;
}
