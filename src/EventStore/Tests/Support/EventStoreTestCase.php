<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\InMemoryEventStore;

abstract class EventStoreTestCase extends TestCase
{
    protected function store(): InMemoryEventStore
    {
        return new InMemoryEventStore();
    }

    /**
     * @param array<string, string> $domainIds
     * @param array<string, mixed>  $payload
     */
    protected static function event(
        string $eventType = 'test.event',
        array $domainIds = [],
        array $payload = [],
    ): Event {
        return new Event(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
        );
    }
}
