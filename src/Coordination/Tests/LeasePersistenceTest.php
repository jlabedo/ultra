<?php

declare(strict_types=1);

namespace Ultra\Coordination\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Ultra\Coordination\Tests\Support\CoordinationTestCase;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLease;
use Ultra\Testing\Concurrent\Cluster;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Time\StatickClock;

/** @internal */
#[CoversNamespace('Ultra\Coordination_Postgres')]
final class LeasePersistenceTest extends CoordinationTestCase
{
    /** @return iterable<string, array{bool, string, string}> */
    public static function reservations(): iterable
    {
        yield 'first lease' => [false, 'acquire', 'acquire'];
        yield 'existing lease' => [true, 'acquire', 'acquire'];
        yield 'first wake' => [false, 'wake', 'wake'];
        yield 'existing wake' => [true, 'wake', 'wake'];
        yield 'first lease suppresses wake' => [false, 'acquire', 'wake'];
        yield 'existing lease suppresses wake' => [true, 'acquire', 'wake'];
    }

    #[DataProvider('reservations')]
    #[Group('slow')]
    public function test_contenders_wait_for_the_transaction_and_only_the_committed_reservation_succeeds(
        bool $existingRow,
        string $operation,
        string $contenderOperation,
    ): void {
        [$connection, $table] = $this->postgresTable('leases');
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = new PostgresCoordinatorLease($connection, $table, $clock);
        if ($existingRow) {
            static::assertTrue($lease->acquire('a', 'seed'));
            $lease->release('a', 'seed');
        }
        $connection->beginTransaction();
        static::assertTrue('acquire' === $operation ? $lease->acquire('a', 'w1') : $lease->markWakeEnqueued('a'));
        $dsn = getenv('ULTRA_TEST_POSTGRES_DSN');
        $cluster = new Cluster(timeout: 3.0);
        $worker = $cluster->spawn(static function () use ($dsn, $table, $contenderOperation): bool {
            $connection = PostgresConnectionSupport::connection($dsn);
            $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
            try {
                $connection->fetchOne("SELECT set_config('application_name', ?, false)", [$table]);
                checkpoint('ready');
                $lease = new PostgresCoordinatorLease($connection, $table, $clock);
                return 'acquire' === $contenderOperation ? $lease->acquire('a', 'w2') : $lease->markWakeEnqueued('a');
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
            $waiting =
                (int) $connection->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [
                    $table,
                ]);
            if (1 === $waiting) {
                break;
            }
            usleep(1_000);
        } while (microtime(true) < $deadline);
        static::assertSame(1, $waiting, 'The contender must wait on the uncommitted reservation.');
        static::assertNull($worker->outcome());
        $connection->commit();
        $outcome = $worker->join();
        static::assertTrue($outcome->isSuccess(), $outcome->exceptionMessage() ?? '');
        static::assertFalse($outcome->value());
        static::assertSame(0, $connection->getTransactionNestingLevel());
        if ('acquire' === $operation) {
            $lease->release('a', 'w2');
            static::assertTrue($lease->hasLiveCoordinator('a'));
        }
    }

    public function test_rollback_discards_ownership(): void
    {
        [$connection, $table] = $this->postgresTable('leases');
        $clock = new StatickClock(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $lease = new PostgresCoordinatorLease($connection, $table, $clock);
        static::assertTrue($lease->acquire('a', 'w1'));
        $lease->release('a', 'w1');
        $connection->beginTransaction();
        static::assertTrue($lease->acquire('a', 'w3'));
        $connection->rollBack();
        static::assertFalse($lease->hasLiveCoordinator('a'));
    }
}
