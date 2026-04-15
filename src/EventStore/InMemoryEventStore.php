<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final class InMemoryEventStore implements EventStore
{
    /** @var list<Event> */
    private array $events = [];

    public function load(StreamQuery $query): LoadResult
    {
        $result = [];
        foreach ($this->events as $event) {
            if (! $query->matches($event)) {
                continue;
            }
            $result[] = $event;
        }

        $queryVersion = $this->buildQueryVersion($query);

        return new LoadResult($result, $queryVersion);
    }

    public function append(array $events, QueryVersion ...$expectedVersions): void
    {
        foreach ($expectedVersions as $expectedVersion) {
            $currentVersion = $this->buildQueryVersion($expectedVersion->query);
            $currentVersion->assertMatches($expectedVersion);
        }

        foreach ($events as $event) {
            $this->appendOne($event);
        }
    }

    private function appendOne(Event $event): void
    {
        $this->events[] = new Event(
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            recordedAt: new \DateTimeImmutable(),
        );
    }

    private function buildQueryVersion(StreamQuery $query): QueryVersion
    {
        $version = 0;
        foreach ($this->events as $event) {
            if (! $query->matches($event)) {
                continue;
            }

            ++$version;
        }

        return new QueryVersion($version, $query);
    }
}
