<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Ultra\EventStore\AppendableEvent;
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

        $callback($store);
    } finally {
        foreach ($schema->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->close();
    }
}

test('query-streams implementation returns db generated recorded_at and query last event id', function () {
    withPostgresQueryStreamsStore(function (EventStore $store): void {
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

test('query-streams implementation does not duplicate events for overlapping OR branches', function () {
    withPostgresQueryStreamsStore(function (EventStore $store): void {
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
