<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\EventStore;
use Ultra\Projection_Postgres\PostgresProjectionRunner;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestSchemaForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestUniquePrefix;

require_once __DIR__ . '/../../EventStore_Postgres/Tests/Support/PostgresTestSupport.php';

function withPostgresProjectionRuntime(callable $callback): void
{
    $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    if (false === $dsn || '' === $dsn) {
        test()->markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres projection integration tests.');
    }

    $connection = postgresTestConnection($dsn);
    $eventStorePrefix = postgresTestUniquePrefix();
    $projectionPrefix = 'ultra_projection_' . bin2hex(random_bytes(4)) . '_';
    $eventSchema = postgresTestSchemaForImplementation($eventStorePrefix, 'query-streams');
    $projectionSchema = new PostgresProjectionSegmentSchema($projectionPrefix);

    try {
        foreach ($eventSchema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        foreach ($projectionSchema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $store = postgresTestStoreForImplementation($connection, $eventStorePrefix, 'query-streams');
        $segmentRepository = new PostgresProjectionSegmentRepository($connection, $projectionPrefix);
        $runner = new PostgresProjectionRunner($store, $segmentRepository);

        $callback($connection, $store, $segmentRepository, $runner, $eventStorePrefix, $projectionPrefix);
    } finally {
        foreach ($projectionSchema->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        foreach ($eventSchema->dropStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $connection->close();
    }
}
