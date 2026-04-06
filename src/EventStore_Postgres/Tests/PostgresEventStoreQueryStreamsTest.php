<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Ultra\EventStore\Event;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;

require_once __DIR__.'/PostgresTestSupport.php';

function withPostgresQueryStreamsStore(callable $callback): void
{
    $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    if (false === $dsn || '' === $dsn) {
        test()->markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres event-store integration tests.');
    }

    $connection = postgresTestConnection($dsn);
    $prefix = postgresTestUniquePrefix();
    $schema = postgresTestSchemaForImplementation($prefix, 'query-streams');

    try {
        foreach ($schema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $store = postgresTestStoreForImplementation($connection, $prefix, 'query-streams');

        $callback($store, $connection, $prefix);
    } finally {
        foreach ($schema->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->close();
    }
}

test('query-streams implementation returns db generated recorded_at and query version', function () {
    withPostgresQueryStreamsStore(function (EventStore $store): void {
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
            ->ofTypes('course.created')
        ;

        $empty = $store->load($query);
        expect($empty->queryVersion->version)->toBe(0);

        $store->append([
            new Event(
                eventType: 'course.created',
                payload: ['name' => 'Math 101'],
                domainIds: ['courseId' => 'course-123'],
            ),
        ]);

        $result = $store->load($query);

        expect($result->events)->toHaveCount(1);
        expect($result->events[0]->recordedAt)->toBeInstanceOf(\DateTimeImmutable::class);
        expect($result->queryVersion->version)->toBe(1);
    });
});

test('query-streams implementation inserts batched events and query streams in order', function () {
    withPostgresQueryStreamsStore(function (EventStore $store): void {
        $courseQuery = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
            ->ofTypes('student.subscribed')
        ;
        $pairQuery = StreamQuery::forStream(
            new DomainIdentifier('courseId', 'course-123'),
            new DomainIdentifier('studentId', 'student-2'),
        )->ofTypes('student.subscribed');

        $store->append([
            new Event(
                eventType: 'student.subscribed',
                payload: ['slot' => 1],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
            new Event(
                eventType: 'student.subscribed',
                payload: ['slot' => 2],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-2'],
            ),
        ]);

        $courseResult = $store->load($courseQuery);
        $pairResult = $store->load($pairQuery);

        expect($courseResult->events)->toHaveCount(2);
        expect($courseResult->events[0]->payload['slot'])->toBe(1);
        expect($courseResult->events[1]->payload['slot'])->toBe(2);
        expect($pairResult->events)->toHaveCount(1);
        expect($pairResult->events[0]->domainIds['studentId'])->toBe('student-2');
        expect($pairResult->queryVersion->version)->toBe(1);
    });
});

test('query-streams implementation stores fixed-width binary digests for query stream keys', function () {
    withPostgresQueryStreamsStore(function (EventStore $store, \Doctrine\DBAL\Connection $connection, string $prefix): void {
        $store->append([
            new Event(
                eventType: 'student.subscribed',
                payload: ['slot' => 1],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ]);

        $keyLengths = $connection->fetchFirstColumn(sprintf(
            'SELECT DISTINCT octet_length(query_stream_key) AS key_length FROM %sevent_query_streams',
            $prefix,
        ));

        expect($keyLengths)->toBe([32]);
    });
});

test('query-streams implementation does not duplicate events for overlapping OR branches', function () {
    withPostgresQueryStreamsStore(function (EventStore $store): void {
        $courseId = new DomainIdentifier('courseId', 'course-123');
        $studentId = new DomainIdentifier('studentId', 'student-1');
        $query = StreamQuery::anyOf(
            StreamQuery::forStream($courseId)->ofTypes('student.subscribed'),
            StreamQuery::forStream($courseId, $studentId)->ofTypes('student.subscribed'),
        );

        $store->append([
            new Event(
                eventType: 'student.subscribed',
                payload: [],
                domainIds: ['facultyId' => 'faculty-1', 'courseId' => 'course-123', 'studentId' => 'student-1'],
            ),
        ]);

        $result = $store->load($query);

        expect($result->events)->toHaveCount(1);
        expect($result->events[0]->eventType)->toBe('student.subscribed');
        expect($result->queryVersion->version)->toBe(1);
    });
});
