<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Buffers event-store appends while preserving read-your-own-pending-writes semantics through the EventStore API.
 *
 * The visible event list includes pending writes, while the `QueryVersion` remains the token from the wrapped
 * store so optimistic concurrency stays aligned with the underlying transaction resource.
 */
final class EventStoreUnitOfWork implements EventStore, UnitOfWork
{
    /** @var list<Event> */
    private array $bufferedEvents = [];

    /** @var list<QueryVersion> */
    private array $expectedVersions = [];

    public function __construct(
        private readonly EventStore $inner,
    ) {}

    public function load(StreamQuery $query): LoadResult
    {
        $baseResult = $this->inner->load($query);
        $pendingEvents = $this->pendingEventsMatching($query);

        return new LoadResult(
            [...$baseResult->events, ...$pendingEvents],
            $baseResult->queryVersion,
        );
    }

    public function append(array $events, QueryVersion ...$expectedVersions): void
    {
        if ([] === $events) {
            return;
        }

        array_push($this->bufferedEvents, ...$events);
        array_push($this->expectedVersions, ...$expectedVersions);
    }

    public function flush(): void
    {
        if ([] === $this->bufferedEvents) {
            return;
        }

        $this->inner->append($this->bufferedEvents, ...$this->expectedVersions);
    }

    public function clear(): void
    {
        $this->bufferedEvents = [];
        $this->expectedVersions = [];
    }

    /**
     * @return list<Event>
     */
    private function pendingEventsMatching(StreamQuery $query): array
    {
        $pendingEvents = [];

        foreach ($this->bufferedEvents as $event) {
            if (! $query->matches($event)) {
                continue;
            }

            $pendingEvents[] = $event;
        }

        return $pendingEvents;
    }
}
