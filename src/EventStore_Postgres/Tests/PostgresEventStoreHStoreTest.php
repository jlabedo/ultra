<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\Sequence;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\Tests\Support\PostgresEventStoreTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class PostgresEventStoreHStoreTest extends PostgresEventStoreTestCase
{
    public function test_load_returns_db_generated_recorded_at_and_a_fresh_append_condition(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('course.created')
            ;

            $empty = $store->load($query);

            $store->append([
                self::event(
                    'course.created',
                    ['name' => 'Math 101'],
                    ['courseId' => 'course-123'],
                ),
            ], $empty->appendCondition());

            $result = $store->load($query);

            self::assertCount(1, $result->events);
            self::assertInstanceOf(\DateTimeImmutable::class, $result->events[0]->recordedAt);
        });
    }

    public function test_append_inserts_multiple_events_in_one_batch(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('course.created')
            ;

            $store->append([
                self::event('course.created', ['name' => 'Math 101'], ['courseId' => 'course-123']),
                self::event('course.created', ['name' => 'Math 101 v2'], ['courseId' => 'course-123']),
            ]);

            $result = $store->load($query);

            self::assertCount(2, $result->events);
            self::assertSame('Math 101', $result->events[0]->payload['name']);
            self::assertSame('Math 101 v2', $result->events[1]->payload['name']);
        });
    }

    public function test_append_validates_multiple_append_conditions(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
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
        });
    }

    public function test_load_round_trips_hstore_domain_identifiers(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $domainIds = ['courseId' => 'course-"123"\example'];
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', $domainIds['courseId']))
                ->ofTypes('course.created')
            ;

            $store->append([
                self::event('course.created', ['name' => 'Math 101'], $domainIds),
            ]);

            $result = $store->load($query);

            self::assertCount(1, $result->events);
            self::assertSame($domainIds, $result->events[0]->domainIds);
        });
    }

    public function test_load_round_trips_event_metadata(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('course.created')
            ;

            $store->append([
                self::event(
                    'course.created',
                    ['name' => 'Math 101'],
                    ['courseId' => 'course-123'],
                    [
                        'message_id' => 'event-1',
                        'correlation_id' => 'trace-1',
                        'tags' => ['a', 'b'],
                    ],
                ),
            ]);

            $result = $store->load($query);

            self::assertCount(1, $result->events);
            self::assertEquals([
                'message_id' => 'event-1',
                'correlation_id' => 'trace-1',
                'tags' => ['a', 'b'],
            ], $result->events[0]->metadata);
        });
    }

    public function test_load_does_not_duplicate_events_for_overlapping_or_branches(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
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
        });
    }

    public function test_gap_checkpoints_use_postgres_array_predicates_for_catch_up_and_replay(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
                ->ofTypes('course.created')
            ;

            $store->append([
                self::event('course.created', ['name' => 'first'], ['courseId' => 'course-123']),
                self::event('course.created', ['name' => 'late'], ['courseId' => 'course-123']),
                self::event('course.created', ['name' => 'gap'], ['courseId' => 'course-123']),
                self::event('course.created', ['name' => 'next'], ['courseId' => 'course-123']),
            ]);

            $checkpoint = new Checkpoint(new Sequence(1), [new Sequence(3)]);

            $catchUp = $store->load($query, from: $checkpoint);
            $replay = $store->load($query, to: $checkpoint);

            self::assertSame(['late', 'next'], array_map(
                static fn (Event $event): string => $event->payload['name'],
                $catchUp->events,
            ));
            self::assertSame(['first', 'gap'], array_map(
                static fn (Event $event): string => $event->payload['name'],
                $replay->events,
            ));
        });
    }

    public function test_pair_query_does_not_conflict_for_same_course_and_different_student(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ]);
            $staleCondition = $store->load($query)->appendCondition();

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-2',
                ]),
            ]);

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ], $staleCondition);

            self::assertCount(2, $store->load($query)->events);
        });
    }

    public function test_pair_query_conflicts_for_same_pair(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ]);
            $staleCondition = $store->load($query)->appendCondition();

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ]);

            $this->expectException(ConcurrencyException::class);

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ], $staleCondition);
        });
    }

    public function test_three_identifier_query_shapes_are_supported(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(
                new DomainIdentifier('facultyId', 'faculty-1'),
                new DomainIdentifier('courseId', 'course-123'),
                new DomainIdentifier('studentId', 'student-1'),
            )->ofTypes('student.subscribed');

            $store->append([
                self::event('student.subscribed', [], [
                    'facultyId' => 'faculty-1',
                    'courseId' => 'course-123',
                    'studentId' => 'student-1',
                ]),
            ]);

            self::assertCount(1, $store->load($query)->events);
        });
    }

    public function test_untyped_queries_load_all_matching_event_types(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'));

            $store->append([
                self::event('course.created', [], ['courseId' => 'course-123']),
                self::event('course.renamed', [], ['courseId' => 'course-123']),
                self::event('course.created', [], ['courseId' => 'course-999']),
            ]);

            $result = $store->load($query);

            self::assertCount(2, $result->events);
        });
    }

    public function test_global_queries_load_all_events(): void
    {
        $this->withPostgresStore(function (PostgresEventStoreHStore $store): void {
            $store->append([
                self::event('course.created', [], ['courseId' => 'course-123']),
                self::event('student.subscribed', [], ['studentId' => 'student-1']),
            ]);

            $result = $store->load(StreamQuery::all());

            self::assertCount(2, $result->events);
            self::assertSame('course.created', $result->events[0]->eventType);
            self::assertSame('student.subscribed', $result->events[1]->eventType);
        });
    }
}
