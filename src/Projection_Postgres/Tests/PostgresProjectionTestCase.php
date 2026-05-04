<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ultra\EventStore\EventStore;


use Ultra\Projection\ProjectionRunner;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivityRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionBucketActivitySchema;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorLeaseRepository;
use Ultra\Projection_Postgres\Coordination\PostgresProjectionCoordinatorSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Projection_Postgres\PostgresProjectionTransactionManager;
use Ultra\Time\ClockInterface;
use Ultra\Time\StatickClock;
use Ultra\Time\SystemClock;


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
        $connection = \Ultra\Testing\PostgresTestSupport::sharedConnection($this->postgresDsn());
        $eventStorePrefix = \Ultra\Testing\PostgresTestSupport::uniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $eventSchema = \Ultra\Testing\PostgresTestSupport::schemaForImplementation($eventStorePrefix, 'query-streams');
        $projectionSchemas = [
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresProjectionBucketActivitySchema($projectionPrefix),
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

            $store = \Ultra\Testing\PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams');
            $segmentRepository = new PostgresProjectionSegmentRepository($connection, $projectionPrefix, $clock);
            $activityRepository = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);
            $runner = new ProjectionRunner(
                $store,
                $segmentRepository,
                new PostgresProjectionTransactionManager($connection),
                $activityRepository,
            );

            $callback($connection, $store, $segmentRepository, $runner, $eventStorePrefix, $projectionPrefix);
        } finally {
            \Ultra\Testing\PostgresTestSupport::resetSharedConnection($connection);

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

    /**
     * @param callable(Connection, string): void $callback
     */
    protected function withPostgresProjectionCoordination(callable $callback): void
    {
        $connection = \Ultra\Testing\PostgresTestSupport::sharedConnection($this->postgresDsn());
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
            \Ultra\Testing\PostgresTestSupport::resetSharedConnection($connection);

            foreach (array_reverse($schemas) as $schema) {
                foreach ($schema->dropStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }
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
        $connection = \Ultra\Testing\PostgresTestSupport::sharedConnection($this->postgresDsn());
        $eventStorePrefix = \Ultra\Testing\PostgresTestSupport::uniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $eventSchema = \Ultra\Testing\PostgresTestSupport::schemaForImplementation($eventStorePrefix, 'query-streams');
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

            $store = \Ultra\Testing\PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams');
            $segmentRepository = new PostgresProjectionSegmentRepository($connection, $projectionPrefix, $clock);
            $activityRepository = new PostgresProjectionBucketActivityRepository($connection, $projectionPrefix);
            $runner = new ProjectionRunner(
                $store,
                $segmentRepository,
                new PostgresProjectionTransactionManager($connection),
                $activityRepository,
            );
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
            \Ultra\Testing\PostgresTestSupport::resetSharedConnection($connection);

            foreach (array_reverse($schemas) as $schema) {
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
