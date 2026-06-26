<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\EventStore;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\Tests\Support\ProjectionFunctionalTestCase;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\ClockInterface;
use Ultra\Time\StatickClock;
use Ultra\Time\SystemClock;

abstract class PostgresProjectionTestCase extends ProjectionFunctionalTestCase
{
    protected function postgresDsn(): string
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres projection integration tests.');
        }

        return $dsn;
    }

    /**
     * @param callable(Connection, EventStore, PostgresProjectionSegmentRepository, ProjectionRunner, string, string): void $callback
     */
    protected function withPostgresProjectionRuntime(callable $callback, ClockInterface $clock = new SystemClock()): void
    {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $eventStorePrefix = PostgresTestSupport::uniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $eventSchema = PostgresTestSupport::schemaForImplementation($eventStorePrefix, 'query-streams');
        $projectionSchemas = [
            new PostgresProjectionSegmentSchema($projectionPrefix),
        ];

        try {
            foreach ($eventSchema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            foreach ($projectionSchemas as $schema) {
                foreach ($schema->createStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $store = PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams');
            $container = $this->projectionUltraLight(
                $store,
                new PostgresProjectionImplementation($connection, $projectionPrefix, $clock),
            )->boot();
            $segmentRepository = $container->projectionSegments();
            $runner = $container->projectionRunner();

            self::assertInstanceOf(PostgresProjectionSegmentRepository::class, $segmentRepository);

            $callback($connection, $store, $segmentRepository, $runner, $eventStorePrefix, $projectionPrefix);
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

            foreach (array_reverse($projectionSchemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            foreach ($eventSchema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }

    protected function staticClock(string $now): StatickClock
    {
        return new StatickClock(new \DateTimeImmutable($now));
    }
}
