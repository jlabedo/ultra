<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\EventStore;

final readonly class EventStoreFixture
{
    public function __construct(
        public EventStore $store,
        public ?Connection $connection = null,
    ) {}
}
