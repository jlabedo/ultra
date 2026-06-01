<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Testing\PostgresTestSupport;
use Ultra\Time\Duration;
use Ultra\Time\StatickClock;

/**
 * @internal
 *
 * @coversNothing
 */
final class PostgresCoordinatorLeaseTest extends TestCase
{
    public function test_only_one_worker_holds_the_lease_and_expiry_frees_it(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->withLease($clock, function (PostgresCoordinatorLease $lease) use ($clock): void {
            self::assertTrue($lease->acquire('global', 'w1', new Duration(seconds: 60)));
            self::assertFalse($lease->acquire('global', 'w2'));
            self::assertTrue($lease->hasLiveCoordinator('global'));

            $clock->setNow(new \DateTimeImmutable('2026-01-01T00:02:00Z'));
            self::assertFalse($lease->hasLiveCoordinator('global'));
            self::assertTrue($lease->acquire('global', 'w2'));
        });
    }

    public function test_release_frees_the_lease(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->withLease($clock, function (PostgresCoordinatorLease $lease): void {
            self::assertTrue($lease->acquire('global', 'w1'));
            $lease->release('global', 'w1');
            self::assertTrue($lease->acquire('global', 'w2'));
        });
    }

    public function test_wake_is_debounced_within_ttl_and_suppressed_while_live(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->withLease($clock, function (PostgresCoordinatorLease $lease) use ($clock): void {
            self::assertTrue($lease->markWakeEnqueued('global', new Duration(seconds: 30)));
            self::assertFalse($lease->markWakeEnqueued('global', new Duration(seconds: 30)));

            $clock->setNow(new \DateTimeImmutable('2026-01-01T00:01:00Z'));
            self::assertTrue($lease->markWakeEnqueued('global', new Duration(seconds: 30)));

            self::assertTrue($lease->acquire('global', 'w1'));
            self::assertFalse($lease->markWakeEnqueued('global', new Duration(seconds: 30)));
        });
    }

    public function test_keys_are_independent(): void
    {
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->withLease($clock, function (PostgresCoordinatorLease $lease): void {
            self::assertTrue($lease->acquire('a', 'w1'));
            self::assertTrue($lease->acquire('b', 'w1'));
            self::assertFalse($lease->acquire('a', 'w2'));
        });
    }

    /**
     * @param callable(PostgresCoordinatorLease): void $callback
     */
    private function withLease(StatickClock $clock, callable $callback): void
    {
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        if (false === $dsn || '' === $dsn) {
            self::markTestSkipped('Set ULTRA_TEST_POSTGRES_DSN to run Postgres coordination tests.');
        }

        $connection = PostgresTestSupport::sharedConnection($dsn);
        $table = 'coordination_leases_'.bin2hex(random_bytes(4));
        $schema = new PostgresCoordinatorLeaseSchema($table);

        try {
            foreach ($schema->createStatements() as $statement) {
                $connection->executeStatement($statement);
            }

            $callback(new PostgresCoordinatorLease($connection, $table, $clock));
        } finally {
            foreach ($schema->dropStatements() as $statement) {
                $connection->executeStatement($statement);
            }
        }
    }
}
