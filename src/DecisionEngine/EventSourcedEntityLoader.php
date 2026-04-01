<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\StoredEvent;
use Ultra\EventStore\StreamQuery;

interface EventSourcedEntityLoader
{
    public function query(DomainIdentifier ...$identifiers): StreamQuery;

    /**
     * @param list<StoredEvent> $events
     */
    public function hydrate(array $events, DomainIdentifier ...$identifiers): object;
}
