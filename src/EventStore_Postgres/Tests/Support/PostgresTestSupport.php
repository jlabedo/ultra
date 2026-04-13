<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\EventStore_Postgres\LockStrategy\LockStrategy;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreSchemaDefinition;

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

function postgresTestStore(Connection $connection, string $tablePrefix): PostgresEventStoreHStore
{
    return new PostgresEventStoreHStore(
        connection: $connection,
        topologyProvider: postgresTestTopologyProvider(),
        tablePrefix: $tablePrefix,
    );
}

function postgresTestSchema(string $tablePrefix): PostgresEventStoreHStoreSchema
{
    return new PostgresEventStoreHStoreSchema($tablePrefix);
}

/**
 * @return list<string>
 */
function postgresTestSupportedImplementations(): array
{
    return ['hstore', 'query-streams'];
}

/**
 * @return list<string>
 */
function postgresTestSupportedLockStrategies(): array
{
    return PostgresEventStoreLockStrategies::names();
}

function postgresTestLockStrategy(string $lockStrategy): LockStrategy
{
    return PostgresEventStoreLockStrategies::fromName($lockStrategy);
}

/**
 * @return PostgresEventStoreHStore|PostgresEventStoreQueryStreams
 */
function postgresTestStoreForImplementation(
    Connection $connection,
    string $tablePrefix,
    string $implementation,
    LoggerInterface $logger = new NullLogger(),
    string $lockStrategy = 'shared-exclusive',
): EventStore
{
    $strategy = postgresTestLockStrategy($lockStrategy);

    return match ($implementation) {
        'hstore' => new PostgresEventStoreHStore(
            connection: $connection,
            topologyProvider: postgresTestTopologyProvider(),
            tablePrefix: $tablePrefix,
            logger: $logger,
            lockStrategy: $strategy,
        ),
        'query-streams' => new PostgresEventStoreQueryStreams(
            connection: $connection,
            topologyProvider: postgresTestTopologyProvider(),
            tablePrefix: $tablePrefix,
            logger: $logger,
            lockStrategy: $strategy,
        ),
        default => throw new \InvalidArgumentException(sprintf(
            'Unsupported Postgres event store implementation "%s". Expected one of: hstore, query-streams.',
            $implementation,
        )),
    };
}

function postgresTestSchemaForImplementation(
    string $tablePrefix,
    string $implementation,
    string $lockStrategy = 'shared-exclusive',
): PostgresEventStoreSchemaDefinition
{
    $strategy = postgresTestLockStrategy($lockStrategy);

    return match ($implementation) {
        'hstore' => new PostgresEventStoreHStoreSchema($tablePrefix, $strategy),
        'query-streams' => new PostgresEventStoreQueryStreamsSchema($tablePrefix, $strategy),
        default => throw new \InvalidArgumentException(sprintf(
            'Unsupported Postgres event store implementation "%s". Expected one of: hstore, query-streams.',
            $implementation,
        )),
    };
}

function postgresTestUniquePrefix(): string
{
    return 'ultra_event_store_' . bin2hex(random_bytes(4)) . '_';
}
