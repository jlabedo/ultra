<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\StreamQuery;

/**
 * @internal
 *
 * @coversNothing
 */
final class EventStoreUnitOfWorkTest extends TestCase
{
    public function test_load_includes_buffered_events_before_flush(): void
    {
        $store = new InMemoryEventStore();
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
        self::assertCount(0, $store->load($query)->events);
    }

    public function test_load_keeps_persisted_events_and_later_matching_buffered_events(): void
    {
        $store = new InMemoryEventStore();
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $store->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Persisted'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Other course'],
                domainIds: ['courseId' => 'course-999'],
            ),
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Pending'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $uow->load($query);

        self::assertSame(['Persisted', 'Pending'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $result->events,
        ));
    }

    public function test_load_from_checkpoint_keeps_later_buffered_events_visible(): void
    {
        $store = new InMemoryEventStore();
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $store->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Persisted'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $checkpoint = $uow->load($query)->checkpoint;

        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Pending'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $uow->load($query, from: $checkpoint);

        self::assertSame(['Pending'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $result->events,
        ));
    }

    public function test_load_keeps_persisted_checkpoint_when_pending_events_are_visible(): void
    {
        $store = new InMemoryEventStore();
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

        $visibleCondition = $uow->load($query)->appendCondition();
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 102'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $visibleCondition);

        $uow->flush();

        self::assertSame(['Math 101', 'Math 102'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $store->load($query)->events,
        ));
    }

    public function test_flush_persists_buffered_appends_in_order(): void
    {
        $store = new InMemoryEventStore();
        $uow = new EventStoreUnitOfWork($store);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $firstCondition = $uow->load($query)->appendCondition();
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $firstCondition);

        $secondCondition = $uow->load($query)->appendCondition();
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 102'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $secondCondition);

        $uow->flush();

        $persisted = $store->load($query);
        self::assertCount(2, $persisted->events);
        self::assertSame('Math 101', $persisted->events[0]->payload['name']);
        self::assertSame('Math 102', $persisted->events[1]->payload['name']);
    }

    public function test_flush_uses_single_inner_append_call_and_passes_persisted_append_conditions_through(): void
    {
        $recordingStore = new RecordingEventStore(
            new InMemoryEventStore(),
        );
        $uow = new EventStoreUnitOfWork($recordingStore);
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $firstCondition = $uow->load($query)->appendCondition();
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $firstCondition);

        $secondCondition = $uow->load($query)->appendCondition();
        $uow->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 102'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $secondCondition);

        $uow->flush();

        self::assertCount(1, $recordingStore->appendCalls);
        self::assertCount(2, $recordingStore->appendCalls[0]['events']);
        self::assertCount(2, $recordingStore->appendCalls[0]['appendConditions']);
        self::assertSame([$firstCondition, $secondCondition], $recordingStore->appendCalls[0]['appendConditions']);
    }

    public function test_clear_drops_buffered_appends(): void
    {
        $store = new InMemoryEventStore();
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
}

final class RecordingEventStore implements EventStore
{
    /** @var list<array{events: list<Event>, appendConditions: list<AppendCondition>}> */
    public array $appendCalls = [];

    public function __construct(
        private readonly EventStore $inner,
    ) {}

    public function load(
        StreamQuery $query,
        ?\Ultra\EventStore\Checkpoint $from = null,
        ?\Ultra\EventStore\Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult
    {
        return $this->inner->load($query, $from, $to, $batchSize);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->appendCalls[] = [
            'events' => $events,
            'appendConditions' => $appendConditions,
        ];

        $this->inner->append($events, ...$appendConditions);
    }
}
