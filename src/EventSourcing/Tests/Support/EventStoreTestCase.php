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
     * @param array<string, mixed>  $metadata
     */
    protected static function event(
        string $eventType = 'test.event',
        array $domainIds = [],
        array $payload = [],
        array $metadata = [],
    ): Event {
        return new Event(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
            metadata: $metadata,
        );
    }
}
