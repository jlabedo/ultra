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
        $pendingEvents = $this->pendingEventsMatching($query);

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
     * @return list<Event>
     */
    private function pendingEventsMatching(StreamQuery $query): array
    {
        $pendingEvents = [];

        foreach ($this->bufferedAppends as $append) {
            foreach ($append->events as $event) {
                if (! $query->matches($event)) {
                    continue;
                }

                $pendingEvents[] = $event;
            }
        }

        return $pendingEvents;
    }
}
