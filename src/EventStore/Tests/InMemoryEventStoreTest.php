<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\Exception\UnsupportedQueryException;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore\Tests\Support\EventStoreTestCase;

/**
 * @internal
 */
#[CoversClass(InMemoryEventStore::class)]
final class InMemoryEventStoreTest extends EventStoreTestCase
{
    public function test_load_with_empty_query_returns_empty_events_and_empty_query_version(): void
    {
        $result = $this->store()->load(new StreamQuery([]));

        self::assertSame([], $result->events);
        self::assertSame(0, $result->queryVersion->version);
    }

    public function test_typed_singleton_query_loads_matching_events_and_returns_versions(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.created');

        $empty = $store->load($query);

        self::assertSame([], $empty->events);
        self::assertSame(0, $empty->queryVersion->version);

        $store->append([
            self::event(
                eventType: 'course.created',
                domainIds: ['courseId' => 'course-123'],
                payload: ['name' => 'Math 101'],
            ),
        ]);

        $result = $store->load($query);

        self::assertCount(1, $result->events);
        self::assertSame('course.created', $result->events[0]->eventType);
        self::assertSame('Math 101', $result->events[0]->payload['name']);
        self::assertSame(1, $result->queryVersion->version);
    }

    public function test_append_with_stale_singleton_query_version_throws_concurrency_exception(): void
    {
        $store = $this->store();
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('course.capacity_changed');

        $staleVersion = $store->load($query)->queryVersion;

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
        ], $staleVersion);
    }

    public function test_append_validates_all_expected_query_versions(): void
    {
        $store = $this->store();
        $courseQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed');
        $pairQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $courseVersion = $store->load($courseQuery)->queryVersion;
        $pairVersion = $store->load($pairQuery)->queryVersion;

        $store->append([
            self::event(
                eventType: 'student.subscribed',
                domainIds: [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ],
            ),
        ], $courseVersion, $pairVersion);

        self::assertCount(1, $store->load($courseQuery)->events);
        self::assertCount(1, $store->load($pairQuery)->events);
    }

    public function test_canonical_pair_query_does_not_conflict_for_same_course_and_different_student(): void
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

        $staleVersion = $store->load($query)->queryVersion;

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
        ], $staleVersion);

        self::assertCount(2, $store->load($query)->events);
    }

    public function test_canonical_pair_query_conflicts_for_the_same_pair(): void
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

        $staleVersion = $store->load($query)->queryVersion;

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
        ], $staleVersion);
    }

    public function test_untyped_branch_throws_unsupported_query_exception(): void
    {
        $this->expectException(UnsupportedQueryException::class);

        $this->store()->load(StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
        ));
    }

    public function test_three_identifier_branch_throws_unsupported_query_exception(): void
    {
        $this->expectException(UnsupportedQueryException::class);

        $this->store()->load(StreamQuery::forStream(
            new DomainIdentifier('facultyId', 'faculty-1'),
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed'));
    }

    public function test_non_canonical_pair_throws_unsupported_query_exception(): void
    {
        $this->expectException(UnsupportedQueryException::class);

        $this->store()->load(StreamQuery::forStream(
            new DomainIdentifier('facultyId', 'faculty-1'),
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed'));
    }

    public function test_mixed_type_branch_with_unsupported_identifiers_throws_unsupported_query_exception(): void
    {
        $this->expectException(UnsupportedQueryException::class);

        $this->store()->load(StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('course.created', 'student.subscribed'));
    }
}
