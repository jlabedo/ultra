<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

final class InMemoryEventStore implements EventStore
{
    /** @var list<StoredEvent> */
    private array $events = [];

    /** @var array<string, int> key is DomainIdentifier::key() => current version */
    private array $versions = [];

    private int $nextSequence = 1;

    public function append(AppendableEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->appendOne($event);
        }
    }

    private function appendOne(AppendableEvent $event): void
    {
        foreach ($event->domainIds as $dimension => $value) {
            $key = (new DomainIdentifier($dimension, $value))->key();
            $current = $this->versions[$key] ?? 0;
            $expected = $event->expectedVersions[$dimension];
            if ($current !== $expected) {
                throw new ConcurrencyException($dimension, $value, $expected, $current);
            }
        }

        $newVersions = [];
        foreach ($event->domainIds as $dimension => $value) {
            $key = (new DomainIdentifier($dimension, $value))->key();
            $next = ($this->versions[$key] ?? 0) + 1;
            $this->versions[$key] = $next;
            $newVersions[$dimension] = $next;
        }

        $stored = new StoredEvent(
            id: $event->id,
            eventType: $event->eventType,
            payload: $event->payload,
            domainIds: $event->domainIds,
            versions: $newVersions,
            sequenceNumber: $this->nextSequence++,
            recordedAt: new \DateTimeImmutable(),
        );
        $this->events[] = $stored;
    }

    public function load(array $domainIds, ?array $eventTypes = null): array
    {
        if ($domainIds === []) {
            return [];
        }

        $eventTypesSet = $eventTypes !== null ? array_fill_keys($eventTypes, true) : null;

        $result = [];
        foreach ($this->events as $event) {
            if (!$this->eventMatchesDomainIds($event, $domainIds)) {
                continue;
            }
            if ($eventTypesSet !== null && !isset($eventTypesSet[$event->eventType])) {
                continue;
            }
            $result[] = $event;
        }

        usort($result, static fn (StoredEvent $a, StoredEvent $b): int => $a->sequenceNumber <=> $b->sequenceNumber);

        return $result;
    }

    /**
     * @param array<DomainIdentifier> $domainIds
     */
    private function eventMatchesDomainIds(StoredEvent $event, array $domainIds): bool
    {
        foreach ($domainIds as $domainId) {
            if (!isset($event->domainIds[$domainId->dimension]) || $event->domainIds[$domainId->dimension] !== $domainId->value) {
                return false;
            }
        }

        return true;
    }
}
