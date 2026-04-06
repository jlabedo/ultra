<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final class InMemoryEventStore implements EventStore
{
    /** @var list<Event> */
    private array $events = [];

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

    private function appendOne(Event $event): void
    {
        $this->queryKeyFactory->keysForEvent($event->eventType, $event->domainIds);
        $this->events[] = new Event(
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            recordedAt: new \DateTimeImmutable(),
        );
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
