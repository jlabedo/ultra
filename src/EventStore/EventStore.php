<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

interface EventStore
{
    /**
     * Load events matching the query (all domain identifier pairs and any of the event names).
     * Returns events ordered by eventId ascending, plus the StreamVersion (last eventId per stream) of the result set.
     */
    public function load(StreamQuery $query): LoadResult;

    /**
     * Append events. If expectedVersion is provided, the store checks that each stream touched by the new events
     * has its current last eventId equal to the one in expectedVersion; otherwise throws ConcurrencyException.
     * If expectedVersion is null, no concurrency check is performed.
     *
     * @param list<AppendableEvent> $events
     *
     * @throws ConcurrencyException when expectedVersion is provided and any stream's last eventId does not match
     */
    public function append(array $events, ?StreamVersion $expectedVersion = null): void;
}
