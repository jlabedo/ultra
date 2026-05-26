<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(InMemoryEventStore::class)]
final class InMemoryEventStoreTest extends EventStoreTestCase
{
    public function test_load_with_empty_query_returns_empty_events(): void
    {
        $result = $this->store()->load(StreamQuery::none());

        self::assertSame([], $result->events);
    }

    public function test_load_with_empty_query_ignores_existing_events(): void
    {
        $store = $this->store();

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $store->load(StreamQuery::none());

        self::assertSame([], $result->events);
    }

    public function test_load_with_all_query_returns_existing_events(): void
    {
        $store = $this->store();

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
            ),
            self::event(
                eventType: 'student.subscribed',
                domainIds: ['studentId' => 'student-1'],
            ),
        ]);

        $result = $store->load(StreamQuery::all());

        self::assertCount(2, $result->events);
        self::assertSame('course.created', $result->events[0]->eventType);
        self::assertSame('student.subscribed', $result->events[1]->eventType);
    }

    public function test_typed_singleton_query_loads_matching_events_and_returns_a_fresh_checkpoint(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $empty = $store->load($query);

        self::assertSame([], $empty->events);

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 101'],
            ),
        ], $empty->appendCondition());

        $result = $store->load($query);

        self::assertCount(1, $result->events);
        self::assertSame('course.created', $result->events[0]->eventType);
        self::assertSame('Math 101', $result->events[0]->payload['name']);
    }

    public function test_load_returns_event_metadata(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'));

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                metadata: [
                    'message_id' => 'event-1',
                    'correlation_id' => 'trace-1',
                    'tags' => ['a', 'b'],
                ],
            ),
        ]);

        $result = $store->load($query);

        self::assertCount(1, $result->events);
        self::assertSame([
            'message_id' => 'event-1',
            'correlation_id' => 'trace-1',
            'tags' => ['a', 'b'],
        ], $result->events[0]->metadata);
    }

    public function test_replay_to_checkpoint_returns_the_snapshot_captured_by_the_checkpoint(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 101'],
            ),
        ]);

        $checkpoint = $store->load($query)->checkpoint;

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-999'],
                payload: ['name' => 'Other course'],
            ),
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 102'],
            ),
        ]);

        $result = $store->load($query, to: $checkpoint);

        self::assertCount(1, $result->events);
        self::assertSame('Math 101', $result->events[0]->payload['name']);
        self::assertSame(['Math 101'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $store->load($query, to: $result->checkpoint)->events,
        ));
        self::assertCount(2, $store->load($query)->events);
    }

    public function test_replay_to_empty_checkpoint_stays_empty_after_matching_events_are_appended(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $emptyCheckpoint = $store->load($query)->checkpoint;

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 101'],
            ),
        ]);

        $result = $store->load($query, to: $emptyCheckpoint);

        self::assertSame([], $result->events);

        $this->expectException(ConcurrencyException::class);

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 102'],
            ),
        ], $result->appendCondition());
    }

    public function test_catch_up_uses_checkpoint_as_the_next_page_cursor(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'));

        $store->append([
            self::event(eventType: 'course.created', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 101']),
            self::event(eventType: 'course.renamed', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 102']),
            self::event(eventType: 'course.archived', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 103']),
        ]);

        $firstPage = $store->load($query, batchSize: 2);
        $secondPage = $store->load($query, from: $firstPage->checkpoint, batchSize: 2);

        self::assertSame(['Math 101', 'Math 102'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $firstPage->events,
        ));
        self::assertSame(['Math 103'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $secondPage->events,
        ));
        self::assertSame(3, $secondPage->checkpoint->gapFloor->value);
    }

    public function test_replay_pagination_returns_a_page_cursor_until_the_bound_is_exhausted(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'));

        $store->append([
            self::event(eventType: 'course.created', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 101']),
            self::event(eventType: 'course.renamed', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 102']),
            self::event(eventType: 'course.archived', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 103']),
        ]);
        $savedCheckpoint = $store->load($query)->checkpoint;

        $store->append([
            self::event(eventType: 'course.restored', domainIds: ['courseId' => 'course-123'], payload: ['name' => 'Math 104']),
        ]);

        $firstPage = $store->load($query, to: $savedCheckpoint, batchSize: 2);
        $secondPage = $store->load($query, from: $firstPage->checkpoint, to: $savedCheckpoint, batchSize: 2);

        self::assertSame(['Math 101', 'Math 102'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $firstPage->events,
        ));
        self::assertSame(2, $firstPage->checkpoint->gapFloor->value);
        self::assertSame(['Math 103'], array_map(
            static fn (Event $event): string => $event->payload['name'],
            $secondPage->events,
        ));
        self::assertEquals($savedCheckpoint, $secondPage->checkpoint);
    }

    public function test_non_matching_events_do_not_make_an_append_condition_stale(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $appendCondition = $store->load($query)->appendCondition();

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-999'],
            ),
        ], $appendCondition);

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $appendCondition);

        $result = $store->load($query);

        self::assertCount(1, $result->events);
    }

    public function test_append_accepts_new_event_types_without_registration(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.deleted');

        $store->append([
            self::event(
                eventType: 'course.deleted',
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        self::assertCount(1, $store->load($query)->events);
    }

    public function test_append_with_stale_singleton_append_condition_throws_concurrency_exception(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.capacity_changed');

        $staleCondition = $store->load($query)->appendCondition();

        $store->append([
            self::event(
                eventType: 'course.capacity_changed',
                domainIds: ['courseId' => 'course-123'],
                payload: ['capacity' => 30],
            ),
        ]);

        $this->expectException(ConcurrencyException::class);

        $store->append([
            self::event(
                eventType: 'course.capacity_changed',
                domainIds: ['courseId' => 'course-123'],
                payload: ['capacity' => 31],
            ),
        ], $staleCondition);
    }

    public function test_append_validates_all_append_conditions(): void
    {
        $store = $this->store();
        $courseQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed');
        $pairQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $courseAppendCondition = $store->load($courseQuery)->appendCondition();
        $pairAppendCondition = $store->load($pairQuery)->appendCondition();

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ], $courseAppendCondition, $pairAppendCondition);

        self::assertCount(1, $store->load($courseQuery)->events);
        self::assertCount(1, $store->load($pairQuery)->events);
    }

    public function test_pair_query_does_not_conflict_for_same_course_and_different_student(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ]);

        $staleCondition = $store->load($query)->appendCondition();

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-2',
                ],
            ),
        ]);

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ], $staleCondition);

        self::assertCount(2, $store->load($query)->events);
    }

    public function test_pair_query_conflicts_for_the_same_pair(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ]);

        $staleCondition = $store->load($query)->appendCondition();

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ]);

        $this->expectException(ConcurrencyException::class);

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ], $staleCondition);
    }

    public function test_untyped_query_loads_all_matching_event_types(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'));

        $store->append([
            self::event(eventType: 'course.created', domainIds: ['courseId' => 'course-123']),
            self::event(eventType: 'course.renamed', domainIds: ['courseId' => 'course-123']),
            self::event(eventType: 'course.created', domainIds: ['courseId' => 'course-999']),
        ]);

        $result = $store->load($query);

        self::assertCount(2, $result->events);
    }

    public function test_query_with_more_than_two_identifiers_loads_matching_events(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('facultyId', 'faculty-1'),
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-2',
                ],
            ),
        ]);

        self::assertCount(1, $store->load($query)->events);
    }

    public function test_pair_queries_do_not_require_registration(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('facultyId', 'faculty-1'),
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed');

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ]);

        self::assertCount(1, $store->load($query)->events);
    }
}
