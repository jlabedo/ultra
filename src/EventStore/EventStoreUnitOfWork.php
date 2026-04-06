<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Buffers event-store appends while preserving read-your-own-pending-writes semantics through the EventStore API.
 */
final class EventStoreUnitOfWork implements EventStore, UnitOfWork
{
    /** @var list<BufferedAppend> */
    private array $bufferedAppends = [];

    public function __construct(
        private readonly EventStore $inner,
    ) {}

    public function load(StreamQuery $query): LoadResult
    {
        $baseResult = $this->inner->load($query);
        $pendingEvents = $this->pendingEventsMatching($query, $this->nextSyntheticEventId($baseResult->events));

        if ([] === $pendingEvents) {
            return $baseResult;
        }

        return new LoadResult(
            [...$baseResult->events, ...$pendingEvents],
            new QueryVersion(
                $baseResult->queryVersion->version + count($pendingEvents),
                $query,
            ),
        );
    }

    public function append(array $events, ?QueryVersion $expectedVersion = null): void
    {
        if ([] === $events) {
            return;
        }

        if (null !== $expectedVersion) {
            $this->load($expectedVersion->query)->queryVersion->assertMatches($expectedVersion);
        }

        $this->bufferedAppends[] = new BufferedAppend($events, $expectedVersion);
    }

    public function flush(): void
    {
        foreach ($this->bufferedAppends as $append) {
            $this->inner->append($append->events, $append->expectedVersion);
        }
    }

    public function clear(): void
    {
        $this->bufferedAppends = [];
    }

    /**
     * @param list<StoredEvent> $persistedEvents
     */
    private function nextSyntheticEventId(array $persistedEvents): int
    {
        if ([] === $persistedEvents) {
            return 1;
        }

        return $persistedEvents[array_key_last($persistedEvents)]->eventId + 1;
    }

    /**
     * @return list<StoredEvent>
     */
    private function pendingEventsMatching(StreamQuery $query, int $nextEventId): array
    {
        $pendingEvents = [];

        foreach ($this->bufferedAppends as $append) {
            foreach ($append->events as $event) {
                $storedEvent = new StoredEvent(
                    eventId: $nextEventId,
                    eventType: $event->eventType,
                    payload: $event->payload,
                    domainIds: $event->domainIds,
                );

                ++$nextEventId;

                if (! $query->matches($storedEvent)) {
                    continue;
                }

                $pendingEvents[] = $storedEvent;
            }
        }

        return $pendingEvents;
    }
}
