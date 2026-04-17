<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

/**
 * Stores events and exposes checkpointed reads plus optimistic concurrency over stream queries.
 */
interface EventStore
{
    /**
     * Load events matching the query.
     */
    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult;

    /**
     * Append events to the store.
     *
     * If append conditions are provided, the store validates that no matching event has become visible after
     * each condition checkpoint. If not, a `ConcurrencyException` is thrown and nothing should be considered
     * committed by the caller.
     *
     * @param list<Event> $events
     *
     * @throws ConcurrencyException when any append condition is provided and its checkpoint has changed
     */
    public function append(array $events, AppendCondition ...$appendConditions): void;
}
