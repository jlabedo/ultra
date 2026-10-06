<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\StreamQuery;

interface EventSourcedEntityLoader
{
    public function query(DomainIdentifier ...$identifiers): StreamQuery;

    /**
     * @param iterable<Event> $events
     */
    public function hydrate(iterable $events, DomainIdentifier ...$identifiers): object;
}
