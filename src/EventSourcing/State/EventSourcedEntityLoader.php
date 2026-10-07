<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;

/** @template TState of object = object */
interface EventSourcedEntityLoader
{
    public function query(DomainIdentifier ...$identifiers): StreamQuery;

    /**
     * @param iterable<Event> $events
     * @return TState
     */
    public function hydrate(iterable $events, DomainIdentifier ...$identifiers): object;
}
