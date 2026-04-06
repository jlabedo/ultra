<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\QueryVersion;
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
        self::assertSame(0, $result->queryVersion->version);
        self::assertCount(0, $store->load($query)->events);
    }

    public function testLoadKeepsPersistedQueryVersionWhenPendingEventsAreVisible(): void
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

        self::assertSame(0, $staleVersion->version);
        self::assertSame(0, $uow->load($query)->queryVersion->version);
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

    public function testFlushUsesSingleInnerAppendCallAndPassesPersistedExpectedVersionsThrough(): void
    {
        $recordingStore = new RecordingEventStore(
            new InMemoryEventStore($this->topologyProvider()),
        );
        $uow = new EventStoreUnitOfWork($recordingStore);
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

        self::assertCount(1, $recordingStore->appendCalls);
        self::assertCount(2, $recordingStore->appendCalls[0]['events']);
        self::assertCount(2, $recordingStore->appendCalls[0]['expectedVersions']);
        self::assertSame([0, 0], array_map(
            static fn (QueryVersion $queryVersion): int => $queryVersion->version,
            $recordingStore->appendCalls[0]['expectedVersions'],
        ));
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

final class RecordingEventStore implements EventStore
{
    /** @var list<array{events: list<Event>, expectedVersions: list<QueryVersion>}> */
    public array $appendCalls = [];

    public function __construct(
        private readonly EventStore $inner,
    ) {}

    public function load(StreamQuery $query): LoadResult
    {
        return $this->inner->load($query);
    }

    public function append(array $events, QueryVersion ...$expectedVersions): void
    {
        $this->appendCalls[] = [
            'events' => $events,
            'expectedVersions' => $expectedVersions,
        ];

        $this->inner->append($events, ...$expectedVersions);
    }
}
