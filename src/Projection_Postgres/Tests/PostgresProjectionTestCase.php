<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Tests;

use Doctrine\DBAL\Connection;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Coordination\ProjectionActivity;
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
            new PostgresDirtyKeyTrackerSchema($projectionPrefix.'dirty_keys'),
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

    /**
     * @param callable(
     *     Connection,
     *     PostgresProjectionSegmentRepository,
     *     ProjectionActivity,
     *     CoordinatorLease,
     *     string
     * ): void $callback
     */
    protected function withPostgresProjectionCoordination(
        callable $callback,
        ClockInterface $clock = new SystemClock(),
    ): void {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $schemas = [
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresCoordinatorLeaseSchema($projectionPrefix.'coordinator_lease'),
            new PostgresDirtyKeyTrackerSchema($projectionPrefix.'dirty_keys'),
        ];

        try {
            foreach ($schemas as $schema) {
                foreach ($schema->createStatements() as $statement) {
                    $connection->executeStatement($statement);
                }
            }

            $container = $this->projectionUltraLight(
                implementation: new PostgresProjectionImplementation($connection, $projectionPrefix, $clock),
            )->boot();
            $segments = $container->projectionSegments();
            $activity = $container->projectionActivity();
            $leases = $container->projectionLeases();

            self::assertInstanceOf(PostgresProjectionSegmentRepository::class, $segments);
            self::assertInstanceOf(ProjectionActivity::class, $activity);
            self::assertInstanceOf(CoordinatorLease::class, $leases);

            $callback($connection, $segments, $activity, $leases, $projectionPrefix);
        } finally {
            PostgresTestSupport::resetSharedConnection($connection);

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
     *     ProjectionActivity,
     *     CoordinatorLease,
     *     string,
     *     string
     * ): void $callback
     */
    protected function withPostgresProjectionCoordinatorRuntime(callable $callback, ClockInterface $clock = new SystemClock()): void
    {
        $connection = PostgresTestSupport::sharedConnection($this->postgresDsn());
        $eventStorePrefix = PostgresTestSupport::uniquePrefix();
        $projectionPrefix = 'ultra_projection_'.bin2hex(random_bytes(4)).'_';
        $eventSchema = PostgresTestSupport::schemaForImplementation($eventStorePrefix, 'query-streams');
        $schemas = [
            new PostgresProjectionSegmentSchema($projectionPrefix),
            new PostgresCoordinatorLeaseSchema($projectionPrefix.'coordinator_lease'),
            new PostgresDirtyKeyTrackerSchema($projectionPrefix.'dirty_keys'),
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

            $store = PostgresTestSupport::storeForImplementation($connection, $eventStorePrefix, 'query-streams');
            $container = $this->projectionUltraLight(
                $store,
                new PostgresProjectionImplementation($connection, $projectionPrefix, $clock),
            )->boot();
            $segmentRepository = $container->projectionSegments();
            $runner = $container->projectionRunner();
            $activityRepository = $container->projectionActivity();
            $leaseRepository = $container->projectionLeases();

            self::assertInstanceOf(PostgresProjectionSegmentRepository::class, $segmentRepository);
            self::assertInstanceOf(ProjectionActivity::class, $activityRepository);
            self::assertInstanceOf(CoordinatorLease::class, $leaseRepository);

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
            PostgresTestSupport::resetSharedConnection($connection);

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
