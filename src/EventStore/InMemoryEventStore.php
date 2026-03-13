<?php

declare(strict_types=1);

namespace Ultra\EventStore;

final class InMemoryEventStore implements EventStore
{
    /** @var list<StoredEvent> */
    private array $events = [];

    /** @var array<string, int> streamId (StreamId::toString()) => last eventId in that stream */
    private array $lastEventIdByStream = [];

    private int $nextEventId = 1;

    public function load(StreamQuery $query): LoadResult
    {
        if ($query->isEmpty()) {
            return new LoadResult([], new StreamVersion([], $query));
        }

        $result = [];
        foreach ($this->events as $event) {
            if (! $query->matches($event)) {
                continue;
            }
            $result[] = $event;
        }

        usort($result, static fn (StoredEvent $a, StoredEvent $b): int => $a->eventId <=> $b->eventId);

        $streamVersion = $this->buildStreamVersionFromEvents($result, $query);

        return new LoadResult($result, $streamVersion);
    }

    public function append(array $events, ?StreamVersion $expectedVersion = null): void
    {
        if (null !== $expectedVersion) {
            $currentResult = $this->load($expectedVersion->query);
            $currentVersionMap = $currentResult->streamVersion->version;
            foreach (array_keys($expectedVersion->version) as $key) {
                if (! array_key_exists($key, $currentVersionMap)) {
                    $currentVersionMap[$key] = $this->lastEventIdByStream[$key] ?? 0;
                }
            }
            $currentVersion = new StreamVersion($currentVersionMap, $expectedVersion->query);
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
            id: $event->id,
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            recordedAt: new \DateTimeImmutable(),
        );
        $this->events[] = $stored;

        foreach (StreamId::fromEvent($event->eventType, $event->domainIds) as $streamId) {
            $this->lastEventIdByStream[$streamId->toString()] = $eventId;
        }
    }

    /**
     * @param list<StoredEvent> $events
     */
    private function buildStreamVersionFromEvents(array $events, StreamQuery $query): StreamVersion
    {
        $version = [];
        foreach ($events as $event) {
            foreach (StreamId::fromEvent($event->eventType, $event->domainIds) as $streamId) {
                $key = $streamId->toString();
                $version[$key] = max($version[$key] ?? 0, $event->eventId);
            }
        }

        return new StreamVersion($version, $query);
    }
}
