<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\Exception\UnsupportedQueryException;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStore;

require_once __DIR__.'/PostgresTestSupport.php';

function withPostgresStore(callable $callback): void
{
    $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    if (false === $dsn || '' === $dsn) {
        test()->markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres event-store integration tests.');
    }

    $connection = postgresTestConnection($dsn);
    $prefix = postgresTestUniquePrefix();
    $schema = postgresTestSchema($prefix);

    try {
        foreach ($schema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $store = postgresTestStore($connection, $prefix);

        $callback($store, $connection);
    } finally {
        foreach ($schema->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }
    }
}

test('load returns db generated recorded_at and query last event id', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
            ->ofTypes('course.created')
        ;

        $empty = $store->load($query);
        expect($empty->queryVersion->lastEventId)->toBe(0);

        $store->append([
            new AppendableEvent(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $store->load($query);

        expect($result->events)->toHaveCount(1);
        expect($result->events[0]->recordedAt)->toBeInstanceOf(\DateTimeImmutable::class);
        expect($result->queryVersion->lastEventId)->toBe(1);
    });
});

test('append inserts multiple events in one batch', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
            ->ofTypes('course.created')
        ;

        $store->append([
            new AppendableEvent(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
            new AppendableEvent(
                eventType: 'course.created',
                payload: ['name' => 'Math 101 v2'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $store->load($query);

        expect($result->events)->toHaveCount(2);
        expect($result->events[0]->eventId)->toBe(1);
        expect($result->events[1]->eventId)->toBe(2);
        expect($result->queryVersion->lastEventId)->toBe(2);
    });
});

test('load round-trips hstore domain identifiers', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $domainIds = ['courseId' => 'course-"123"\\example'];
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', $domainIds['courseId']))
            ->ofTypes('course.created')
        ;

        $store->append([
            new AppendableEvent(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: $domainIds,
            ),
        ]);

        $result = $store->load($query);

        expect($result->events)->toHaveCount(1);
        expect($result->events[0]->domainIds)->toBe($domainIds);
    });
});

test('load does not duplicate events for overlapping OR branches', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $courseId = new DomainIdentifier('courseId', 'course-123');
        $studentId = new DomainIdentifier('studentId', 'student-1');
        $query = StreamQuery::anyOf(
            StreamQuery::forStream($courseId)->ofTypes('student.subscribed'),
            StreamQuery::forStream($courseId, $studentId)->ofTypes('student.subscribed'),
        );

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ]);

        $result = $store->load($query);

        expect($result->events)->toHaveCount(1);
        expect($result->events[0]->eventType)->toBe('student.subscribed');
        expect($result->queryVersion->lastEventId)->toBe(1);
    });
});

test('canonical pair does not conflict for same course and different student', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ]);
        $staleVersion = $store->load($query)->queryVersion;

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
            ),
        ]);

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ], $staleVersion);

        expect($store->load($query)->events)->toHaveCount(2);
    });
});

test('canonical pair conflicts for same pair', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $query = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-1'),
        )->ofTypes('student.subscribed');

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ]);
        $staleVersion = $store->load($query)->queryVersion;

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ]);

        $store->append([
            new AppendableEvent(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ], $staleVersion);
    });
})->throws(ConcurrencyException::class);

test('unsupported query shapes throw before hitting the database', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $store->load(StreamQuery::forStream(
            new DomainIdentifier('facultyId', 'faculty-1'),
            new DomainIdentifier('courseId', 'course-123'),
        )->ofTypes('student.subscribed'));
    });
})->throws(UnsupportedQueryException::class);
