<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\Tests\Support\PostgresEventStoreTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresEventStoreQueryStreamsTest extends PostgresEventStoreTestCase
{
    public function test_query_streams_implementation_returns_db_generated_recorded_at_and_a_fresh_append_condition(): void
    {
        $this->withPostgresStore(function (EventStore $store, Connection $_connection, string $_prefix): void {
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('course.created')
            ;

            $empty = $store->load($query);

            $store->append([
                self::event('course.created', ['name' => 'Math 101'], ['courseId' => 'course-123']),
            ], $empty->appendCondition());

            $result = $store->load($query);

            self::assertCount(1, $result->events);
            self::assertInstanceOf(\DateTimeImmutable::class, $result->events[0]->recordedAt);
        }, 'query-streams');
    }

    public function test_query_streams_implementation_inserts_batched_events_and_query_streams_in_order(): void
    {
        $this->withPostgresStore(function (EventStore $store, Connection $_connection, string $_prefix): void {
            $courseQuery = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('student.subscribed')
            ;
            $pairQuery = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-2'),
            )->ofTypes('student.subscribed');

            $store->append([
                self::event('student.subscribed', ['slot' => 1], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
                self::event('student.subscribed', ['slot' => 2], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-2',
                ]),
            ]);

            $courseResult = $store->load($courseQuery);
            $pairResult = $store->load($pairQuery);

            self::assertCount(2, $courseResult->events);
            self::assertSame(1, $courseResult->events[0]->payload['slot']);
            self::assertSame(2, $courseResult->events[1]->payload['slot']);
            self::assertCount(1, $pairResult->events);
            self::assertSame('student-2', $pairResult->events[0]->domainIds['studentId']);
        }, 'query-streams');
    }

    public function test_query_streams_implementation_validates_multiple_append_conditions(): void
    {
        $this->withPostgresStore(function (EventStore $store, Connection $_connection, string $_prefix): void {
            $courseQuery = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('student.subscribed')
            ;
            $pairQuery = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');

            $courseAppendCondition = $store->load($courseQuery)->appendCondition();
            $pairAppendCondition = $store->load($pairQuery)->appendCondition();

            $store->append([
                self::event('student.subscribed', ['slot' => 1], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ], $courseAppendCondition, $pairAppendCondition);

            self::assertCount(1, $store->load($courseQuery)->events);
            self::assertCount(1, $store->load($pairQuery)->events);
        }, 'query-streams');
    }

    public function test_query_streams_implementation_does_not_duplicate_events_for_overlapping_or_branches(): void
    {
        $this->withPostgresStore(function (EventStore $store, Connection $_connection, string $_prefix): void {
            $courseId = new DomainIdentifier('courseId', 'course-123');
            $studentId = new DomainIdentifier('studentId', 'student-1');
            $query = StreamQuery::anyOf(
                StreamQuery::forStream($courseId)->ofTypes('student.subscribed'),
                StreamQuery::forStream($courseId, $studentId)->ofTypes('student.subscribed'),
            );

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ]);

            $result = $store->load($query);

            self::assertCount(1, $result->events);
            self::assertSame('student.subscribed', $result->events[0]->eventType);
        }, 'query-streams');
    }

    public function test_query_streams_implementation_supports_untyped_multi_identifier_queries(): void
    {
        $this->withPostgresStore(function (EventStore $store, Connection $_connection, string $_prefix): void {
            $query = StreamQuery::forStream(
                new DomainIdentifier('facultyId', 'faculty-1'),
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            );

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
                self::event('student.unsubscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-2',
                ]),
            ]);

            $result = $store->load($query);

            self::assertCount(2, $result->events);
        }, 'query-streams');
    }
}
