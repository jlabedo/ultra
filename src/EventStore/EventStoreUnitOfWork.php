<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Buffers event-store appends while preserving read-your-own-pending-writes semantics through the EventStore API.
 *
 * The visible event list includes pending writes, while the checkpoint comes from the wrapped store so optimistic
 * concurrency stays aligned with the underlying transaction resource.
 */
final class EventStoreUnitOfWork implements EventStore, UnitOfWork
{
    /** @var list<Event> */
    private array $bufferedEvents = [];

    /** @var list<AppendCondition> */
    private array $appendConditions = [];

    public function __construct(
        private readonly EventStore $inner,
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult
    {
        $baseResult = $this->inner->load($query, $from, $to, $batchSize);
        $pendingEvents = null === $to ? $this->pendingEventsMatching($query) : [];

        return new LoadResult(
            [...$baseResult->events, ...$pendingEvents],
            $baseResult->checkpoint,
        );
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        if ([] === $events) {
            return;
        }

        array_push($this->bufferedEvents, ...$events);
        array_push($this->appendConditions, ...$appendConditions);
    }

    public function flush(): void
    {
        if ([] === $this->bufferedEvents) {
            return;
        }

        $this->inner->append($this->bufferedEvents, ...$this->appendConditions);
    }

    public function clear(): void
    {
        $this->bufferedEvents = [];
        $this->appendConditions = [];
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
