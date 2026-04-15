<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * One OR branch of a stream query.
 *
 * Domain identifiers are matched with AND semantics. Event types are matched with OR semantics,
 * where an empty event type list means all event types.
 */
readonly class StreamQueryBranch
{
    /** @var array<string, string> */
    public array $domainIdentifiers;

    /** @var list<string> */
    public array $eventTypes;

    /**
     * @param array<string, string> $domainIdentifiers
     * @param list<string>          $eventTypes
     */
    public function __construct(array $domainIdentifiers, array $eventTypes = [])
    {
        ksort($domainIdentifiers);

        $this->domainIdentifiers = $domainIdentifiers;
        $this->eventTypes = array_values(array_unique($eventTypes));
    }

    public function isEmpty(): bool
    {
        return [] === $this->domainIdentifiers;
    }

    public function withEventTypes(string ...$eventTypes): self
    {
        return new self($this->domainIdentifiers, array_values($eventTypes));
    }

    public function matches(Event $event): bool
    {
        if ([] !== $this->eventTypes && ! in_array($event->eventType, $this->eventTypes, true)) {
            return false;
        }

        foreach ($this->domainIdentifiers as $dimension => $value) {
            if (! isset($event->domainIds[$dimension]) || $event->domainIds[$dimension] !== $value) {
                return false;
            }
        }

        return true;
    }
}
