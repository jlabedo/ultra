<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final class InMemoryEventStore implements EventStore
{
    /** @var list<StoredEvent> */
    private array $events = [];

    /** @var array<string, int> canonical query stream key => last eventId */
    private array $lastEventIdByQueryStream = [];

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
            return new LoadResult([], new QueryVersion([], $query));
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
        $eventId = $this->nextEventId++;

        $stored = new StoredEvent(
            eventId: $eventId,
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            recordedAt: new \DateTimeImmutable(),
        );
        $this->events[] = $stored;

        foreach ($this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds) as $queryStreamKey) {
            $this->lastEventIdByQueryStream[$queryStreamKey] = $eventId;
        }
    }

    private function buildQueryVersion(CanonicalQuery $canonicalQuery): QueryVersion
    {
        $version = [];
        foreach ($canonicalQuery->allKeys() as $key) {
            $version[$key] = $this->lastEventIdByQueryStream[$key] ?? 0;
        }

        return new QueryVersion($version, $canonicalQuery->query);
    }
}
