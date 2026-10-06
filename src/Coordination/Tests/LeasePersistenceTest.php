<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Ultra\Coordination\Tests\Support\CoordinationTestCase;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\Coordination_Postgres')]
final class LeasePersistenceTest extends CoordinationTestCase
{
    public function test_contenders_wait_for_the_transaction_and_rollback_discards_ownership(): void
    {
        [$connection, $table] = $this->postgresTable('leases');
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = new PostgresCoordinatorLease($connection, $table, $clock);
        $lease->markWakeEnqueued('a');
        $connection->beginTransaction();
        self::assertTrue($lease->acquire('a', 'w1'));
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        $cluster = new Cluster(timeout: 3.0);
        $worker = $cluster->spawn(static function () use ($dsn, $table): bool {
            $connection = PostgresConnectionSupport::connection($dsn);
            $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
            try {
                $connection->fetchOne("SELECT set_config('application_name', ?, false)", [$table]);
                checkpoint('ready');
                return new PostgresCoordinatorLease($connection, $table, $clock)->acquire('a', 'w2');
            } finally {
                $connection->close();
            }
        });
        $worker->awaitCheckpoint('ready');
        $worker->release('ready');
        $deadline = microtime(true) + 2.0;
        do {
            $worker->pump();
            $connection->executeStatement('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) $connection->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$table]);
            if (1 === $waiting) {
                break;
            }
            usleep(1_000);
        } while (microtime(true) < $deadline);
        self::assertSame(1, $waiting, 'The contender must wait on the uncommitted lease.');
        self::assertNull($worker->outcome());
        $connection->commit();
        $outcome = $worker->join();
        self::assertTrue($outcome->isSuccess(), $outcome->exceptionMessage() ?? '');
        self::assertFalse($outcome->value());
        self::assertSame(0, $connection->getTransactionNestingLevel());
        $lease->release('a', 'w1');
        $connection->beginTransaction();
        self::assertTrue($lease->acquire('a', 'w3'));
        $connection->rollBack();
        self::assertFalse($lease->hasLiveCoordinator('a'));
    }
}
