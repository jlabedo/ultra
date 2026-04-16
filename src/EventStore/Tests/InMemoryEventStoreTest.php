<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
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

    public function test_typed_singleton_query_loads_matching_events_and_returns_a_fresh_versioned_query(): void
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
        ], $empty->versionedQuery);

        $result = $store->load($query);

        self::assertCount(1, $result->events);
        self::assertSame('course.created', $result->events[0]->eventType);
        self::assertSame('Math 101', $result->events[0]->payload['name']);
    }

    public function test_load_accepts_a_versioned_query_to_reload_the_same_query(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $versionedQuery = $store->load($query)->versionedQuery;

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 101'],
            ),
        ]);

        $result = $store->load($versionedQuery);

        self::assertCount(1, $result->events);
        self::assertSame('Math 101', $result->events[0]->payload['name']);
        self::assertNotSame($versionedQuery, $result->versionedQuery);
        self::assertSame($query, $result->versionedQuery->query);
    }

    public function test_non_matching_events_do_not_make_a_versioned_query_stale(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $versionedQuery = $store->load($query)->versionedQuery;

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-999'],
            ),
        ], $versionedQuery);

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
            ),
        ], $versionedQuery);

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

    public function test_append_with_stale_singleton_versioned_query_throws_concurrency_exception(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.capacity_changed');

        $staleQuery = $store->load($query)->versionedQuery;

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
        ], $staleQuery);
    }

    public function test_append_validates_all_expected_versioned_queries(): void
    {
        $store = $this->store();
        $courseQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed');
        $pairQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $courseVersionedQuery = $store->load($courseQuery)->versionedQuery;
        $pairVersionedQuery = $store->load($pairQuery)->versionedQuery;

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ], $courseVersionedQuery, $pairVersionedQuery);

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

        $staleQuery = $store->load($query)->versionedQuery;

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
        ], $staleQuery);

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

        $staleQuery = $store->load($query)->versionedQuery;

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
        ], $staleQuery);
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
