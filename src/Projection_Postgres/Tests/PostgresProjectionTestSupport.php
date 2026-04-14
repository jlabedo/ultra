<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Ultra\Projection_Postgres\PostgresEventFeed;
use Ultra\Projection_Postgres\PostgresProjectionRuntimeRepository;
use Ultra\Projection_Postgres\PostgresProjectionRuntimeSchema;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestSchemaForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestUniquePrefix;

require_once __DIR__.'/../../EventStore_Postgres/Tests/Support/PostgresTestSupport.php';

function withPostgresProjectionRuntime(callable $callback): void
{
    $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
    if (false === $dsn || '' === $dsn) {
        test()->markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres projection integration tests.');
    }

    $connection = postgresTestConnection($dsn);
    $eventStorePrefix = postgresTestUniquePrefix();
    $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
    $eventSchema = postgresTestSchemaForImplementation($eventStorePrefix, 'query-streams');
    $projectionSchema = new PostgresProjectionRuntimeSchema($projectionPrefix);

    try {
        foreach ($eventSchema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        foreach ($projectionSchema->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $store = postgresTestStoreForImplementation($connection, $eventStorePrefix, 'query-streams');
        $feed = PostgresEventFeed::forJsonb($connection, $eventStorePrefix);
        $repository = new PostgresProjectionRuntimeRepository($connection, $projectionPrefix);

        $callback($connection, $store, $feed, $repository, $eventStorePrefix, $projectionPrefix);
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
