<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

interface EventStore
{
    /**
     * Append one or more events. Expected versions are checked per dimension; on first mismatch, ConcurrencyException is thrown.
     * Events are processed in order; each event's append updates version counters for the next.
     *
     * @throws ConcurrencyException when expected version does not match current version for any (dimension, value)
     */
    public function append(AppendableEvent ...$events): void;

    /**
     * Load events that match ALL of the given domain identifiers, optionally filtered by event types.
     * Returns events ordered by sequenceNumber ascending.
     *
     * @param array<DomainIdentifier> $domainIds events must contain every (dimension, value) in this list
     * @param array<string>|null $eventTypes optional list of event type names to include; null means all
     *
     * @return list<StoredEvent>
     */
    public function load(array $domainIds, ?array $eventTypes = null): array;
}
