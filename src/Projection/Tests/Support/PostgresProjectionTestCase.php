<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Support;

use Doctrine\DBAL\Connection;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Coordination\ProjectionActivity;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection_Postgres\PostgresProjectionSegmentRepository;
use Ultra\Time\ClockInterface;
use Ultra\Time\StatickClock;
use Ultra\Time\SystemClock;

abstract class PostgresProjectionTestCase extends ProjectionTestCase
{
    protected function postgresDsn(): string
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN for Postgres projection guarantees.');
        }

        return $dsn;
    }

    /** @param callable(Connection, PostgresProjectionSegmentRepository, ProjectionActivity, CoordinatorLease, string): void $callback */
    protected function withPostgresProjectionCoordination(
        callable $callback,
        ClockInterface $clock = new SystemClock(),
    ): void {
        $fixture = $this->fixture('postgres', $clock);
        $callback($fixture->connection, $fixture->segments, $fixture->activity, $fixture->leases, $fixture->prefix);
    }

    /** @param callable(Connection, EventStore, PostgresProjectionSegmentRepository, ProjectionRunner, ProjectionActivity, CoordinatorLease, string, string): void $callback */
    protected function withPostgresProjectionCoordinatorRuntime(
        callable $callback,
        ClockInterface $clock = new SystemClock(),
    ): void {
        $fixture = $this->fixture('postgres', $clock);
        $fixture->registerReadModel($fixture->prefix . 'course_names_view');
        $fixture->registerReadModel($fixture->prefix . 'harness_course_names_view');
        $callback(
            $fixture->connection,
            $fixture->store,
            $fixture->segments,
            $fixture->runner,
            $fixture->activity,
            $fixture->leases,
            $fixture->prefix . 'event_',
            $fixture->prefix,
        );
    }

    protected function staticClock(string $now): StatickClock
    {
        return new StatickClock(new \DateTimeImmutable($now));
    }
}
