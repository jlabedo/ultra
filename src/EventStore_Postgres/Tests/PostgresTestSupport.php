<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore_Postgres\PostgresEventStore;
use Ultra\EventStore_Postgres\PostgresEventStoreSchema;

function postgresTestConnection(string $dsn): Connection
{
    return DriverManager::getConnection(
        new DsnParser([
            'postgres' => 'pgsql',
            'postgresql' => 'pgsql',
        ])->parse($dsn),
    );
}

function postgresTestTopologyProvider(): StaticEventTopologyProvider
{
    return new StaticEventTopologyProvider([
        'course.created' => new EventTopology(['courseId']),
        'course.capacity_changed' => new EventTopology(['courseId']),
        'student.subscribed' => new EventTopology(['facultyId', 'courseId', 'studentId'], [
            'courseId' => ['facultyId'],
            'studentId' => ['facultyId'],
        ]),
    ]);
}

function postgresTestStore(Connection $connection, string $tablePrefix): PostgresEventStore
{
    return new PostgresEventStore(
        connection: $connection,
        topologyProvider: postgresTestTopologyProvider(),
        tablePrefix: $tablePrefix,
    );
}

function postgresTestSchema(string $tablePrefix): PostgresEventStoreSchema
{
    return new PostgresEventStoreSchema($tablePrefix);
}

function postgresTestUniquePrefix(): string
{
    return 'ultra_event_store_'.bin2hex(random_bytes(4)).'_';
}
