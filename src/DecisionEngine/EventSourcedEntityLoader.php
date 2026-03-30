<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Ultra\EventStore\DomainIdentifier;

interface EventSourcedEntityLoader
{
    public function load(DomainIdentifier ...$identifiers): object;
}
