<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StaticEventTopologyProvider;

abstract class EventStoreTestCase extends TestCase
{
    protected function topologyProvider(): StaticEventTopologyProvider
    {
        return new StaticEventTopologyProvider([
            'course.created' => new EventTopology(['courseId']),
            'course.capacity_changed' => new EventTopology(['courseId']),
            'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
                'courseId' => ['facultyId'],
            ]),
            'student.unsubscribed' => new EventTopology(['courseId', 'studentId']),
        ]);
    }

    protected function store(): InMemoryEventStore
    {
        return new InMemoryEventStore($this->topologyProvider());
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
