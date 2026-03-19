<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\Exception\ConcurrencyException;
use Ultra\EventStore\Exception\UnsupportedQueryException;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore\StreamQuery;
use Ultra\EventStore_Postgres\PostgresEventStore;
use Ultra\EventStore_Postgres\PostgresEventStoreSchema;

function withPostgresStore(callable $callback): void
{
    $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    if (false === $dsn || '' === $dsn) {
        test()->markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres event-store integration tests.');
    }

    $connection = DriverManager::getConnection(
        new DsnParser([
            'postgres' => 'pgsql',
            'postgresql' => 'pgsql',
        ])->parse($dsn),
    );
    $prefix = 'ultra_event_store_'.bin2hex(random_bytes(4)).'_';
    $schema = new PostgresEventStoreSchema($prefix);

    try {
        foreach ($schema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $store = new PostgresEventStore(
            connection: $connection,
            topologyProvider: new StaticEventTopologyProvider([
                'course.created' => new EventTopology(['courseId']),
                'course.capacity_changed' => new EventTopology(['courseId']),
                'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
                    'courseId' => ['facultyId'],
                    'studentId' => ['facultyId'],
                ]),
            ]),
            tablePrefix: $prefix,
        );

        $callback($store, $connection);
    } finally {
        foreach ($schema->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }
    }
}

test('load returns db generated recorded_at and query version entries', function () {
    withPostgresStore(function (PostgresEventStore $store): void {
        $query = StreamQuery::forStream(new DomainIdentifier('courseId', 'course-123'))
            ->ofTypes('course.created')
        ;

        $empty = $store->load($query);
        expect(array_values($empty->queryVersion->toArray()))->toBe([0]);

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
        expect(array_values($result->queryVersion->toArray()))->toBe([1]);
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
