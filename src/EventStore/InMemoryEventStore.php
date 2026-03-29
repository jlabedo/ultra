<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final class InMemoryEventStore implements EventStore
{
    /** @var list<StoredEvent> */
    private array $events = [];

    private int $nextEventId = 1;

    private CanonicalQueryKeyFactory $queryKeyFactory;

    public function __construct(?EventTopologyProvider $topologyProvider = null)
    {
        $this->queryKeyFactory = new CanonicalQueryKeyFactory($topologyProvider ?? new StaticEventTopologyProvider([]));
    }

    public function load(StreamQuery $query): LoadResult
    {
        $canonicalQuery = $this->queryKeyFactory->resolveQuery($query);
        if ($query->isEmpty()) {
            return new LoadResult([], QueryVersion::empty($query));
        }

        $result = [];
        foreach ($this->events as $event) {
            if (! $query->matches($event)) {
                continue;
            }
            $result[] = $event;
        }

        usort($result, static fn (StoredEvent $a, StoredEvent $b): int => $a->eventId <=> $b->eventId);

        $queryVersion = $this->buildQueryVersion($canonicalQuery);

        return new LoadResult($result, $queryVersion);
    }

    public function append(array $events, ?QueryVersion $expectedVersion = null): void
    {
        if (null !== $expectedVersion) {
            $currentVersion = $this->buildQueryVersion($this->queryKeyFactory->resolveQuery($expectedVersion->query));
            $currentVersion->assertMatches($expectedVersion);
        }

        foreach ($events as $event) {
            $this->appendOne($event);
        }
    }

    private function appendOne(AppendableEvent $event): void
    {
        $this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds);

        $eventId = $this->nextEventId++;

        $stored = new StoredEvent(
            eventId: $eventId,
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            recordedAt: new \DateTimeImmutable(),
        );
        $this->events[] = $stored;
    }

    private function buildQueryVersion(CanonicalQuery $canonicalQuery): QueryVersion
    {
        $version = 0;
        foreach ($this->events as $event) {
            if (! $canonicalQuery->query->matches($event)) {
                continue;
            }

            ++$version;
        }

        return new QueryVersion($version, $canonicalQuery->query);
    }
}
