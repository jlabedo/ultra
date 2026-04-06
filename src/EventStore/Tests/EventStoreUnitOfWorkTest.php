<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StreamQuery;

final class EventStoreUnitOfWorkTest extends TestCase
{
    public function testLoadIncludesBufferedEventsBeforeFlush(): void
    {
        $store = new InMemoryEventStore($this->topologyProvider());
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $uow->load($query);

        self::assertCount(1, $result->events);
        self::assertSame(1, $result->queryVersion->version);
        self::assertCount(0, $store->load($query)->events);
    }

    public function testAppendValidatesAgainstOverlayVersion(): void
    {
        $store = new InMemoryEventStore($this->topologyProvider());
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $staleVersion = $uow->load($query)->queryVersion;

        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $this->expectException(ConcurrencyException::class);

        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 102'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $staleVersion);
    }

    public function testFlushPersistsBufferedAppendsInOrder(): void
    {
        $store = new InMemoryEventStore($this->topologyProvider());
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $firstVersion = $uow->load($query)->queryVersion;
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $firstVersion);

        $secondVersion = $uow->load($query)->queryVersion;
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 102'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $secondVersion);

        $uow->flush();

        $persisted = $store->load($query);
        self::assertCount(2, $persisted->events);
        self::assertSame(2, $persisted->queryVersion->version);
        self::assertSame('Math 101', $persisted->events[0]->payload['name']);
        self::assertSame('Math 102', $persisted->events[1]->payload['name']);
    }

    public function testClearDropsBufferedAppends(): void
    {
        $store = new InMemoryEventStore($this->topologyProvider());
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $uow->clear();
        $uow->flush();

        self::assertCount(0, $store->load($query)->events);
    }

    private function topologyProvider(): StaticEventTopologyProvider
    {
        return new StaticEventTopologyProvider([
            'course.created' => new EventTopology(['courseId']),
            'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
                'courseId' => ['facultyId'],
            ]),
        ]);
    }
}
