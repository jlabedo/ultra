<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ultra\EventStore\EventStore;

use function Ultra\EventStore_Postgres\Tests\Support\postgresTestConnection;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestSchemaForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestStoreForImplementation;
use function Ultra\EventStore_Postgres\Tests\Support\postgresTestUniquePrefix;

use Ultra\Projection\ProjectionRunner;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivitySchema;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Time\StatickClock;
use Ultra\Time\SystemClock;

require_once __DIR__.'/../../EventStore_Postgres/Tests/Support/PostgresTestSupport.php';

abstract class PostgresProjectionTestCase extends TestCase
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
        $connection = postgresTestConnection($this->postgresDsn());
        $eventStorePrefix = postgresTestUniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
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
            $segmentRepository = new PostgresProjectionSegmentRepository($connection, $projectionPrefix, $clock);
            $runner = new ProjectionRunner($store, $segmentRepository);

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

    /**
     * @param callable(Connection, string): void $callback
     */
    protected function withPostgresProjectionCoordination(callable $callback): void
    {
        $connection = postgresTestConnection($this->postgresDsn());
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $schemas = [
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresProjectionCoordinatorSchema($projectionPrefix),
            new PostgresProjectionBucketActivitySchema($projectionPrefix),
        ];

        try {
            foreach ($schemas as $schema) {
                foreach ($schema->createStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $callback($connection, $projectionPrefix);
        } finally {
            foreach (array_reverse($schemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $connection->close();
        }
    }

    /**
     * @param callable(
     *     Connection,
     *     EventStore,
     *     PostgresProjectionSegmentRepository,
     *     ProjectionRunner,
     *     PostgresProjectionBucketActivityRepository,
     *     PostgresProjectionCoordinatorLeaseRepository,
     *     string,
     *     string
     * ): void $callback
     */
    protected function withPostgresProjectionCoordinatorRuntime(callable $callback, ClockInterface $clock = new SystemClock()): void
    {
        $connection = postgresTestConnection($this->postgresDsn());
        $eventStorePrefix = postgresTestUniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $eventSchema = postgresTestSchemaForImplementation($eventStorePrefix, 'query-streams');
        $schemas = [
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresProjectionCoordinatorSchema($projectionPrefix),
            new PostgresProjectionBucketActivitySchema($projectionPrefix),
        ];

        try {
            foreach ($eventSchema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            foreach ($schemas as $schema) {
                foreach ($schema->createStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $store = postgresTestStoreForImplementation($connection, $eventStorePrefix, 'query-streams');
            $segmentRepository = new PostgresProjectionSegmentRepository($connection, $projectionPrefix, $clock);
            $runner = new ProjectionRunner($store, $segmentRepository);
            $activityRepository = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);
            $leaseRepository = new PostgresProjectionCoordinatorLeaseRepository($connection, $projectionPrefix);

            $callback(
                $connection,
                $store,
                $segmentRepository,
                $runner,
                $activityRepository,
                $leaseRepository,
                $eventStorePrefix,
                $projectionPrefix,
            );
        } finally {
            foreach (array_reverse($schemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            foreach ($eventSchema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $connection->close();
        }
    }

    protected function staticClock(string $now): StatickClock
    {
        return new StatickClock(new \DateTimeImmutable($now));
    }
}
