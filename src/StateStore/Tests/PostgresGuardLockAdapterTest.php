<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\Tests\PostgresMappedRowSetTestCase;
use Ultra\StateStore\PostgresGuardLockAdapter;
use Ultra\StateStore\StateTableRegistry;
use Ultra\Testing\PostgresTestSupport;

/** @internal */
final class PostgresGuardLockAdapterTest extends PostgresMappedRowSetTestCase
{
    public function test_manual_locks_are_deferred_and_held_until_the_transaction_ends(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresTestSupport::connection(getenv('ULTRA_TEST_POSTGRES_DSN'));
            $otherRegistry = new StateTableRegistry([], guardLockAdapter: new PostgresGuardLockAdapter($other));
            $registry = new StateTableRegistry([], guardLockAdapter: new PostgresGuardLockAdapter($connection));
            try {
                // The anchor need not exist, and registering it outside a transaction takes no lock.
                $registry->lock($tableName, new MappedKey(['id' => 'anchor']));
                self::assertTrue($registry->hasPendingChanges());
                $otherRegistry->lock($tableName, new MappedKey(['id' => 'anchor']));
                $other->transactional($otherRegistry->flush(...));

                $connection->beginTransaction();
                $registry->flush();
                $other->beginTransaction();
                $other->executeStatement("SET LOCAL lock_timeout = '50ms'");
                try {
                    $otherRegistry->flush();
                    self::fail('The second flush must wait for the first transaction.');
                } catch (DriverException $exception) {
                    self::assertSame('55P03', $exception->getSQLState());
                } finally {
                    $other->rollBack();
                }
                $connection->commit();
                $other->transactional($otherRegistry->flush(...));
                $registry->clear();
                self::assertFalse($registry->hasPendingChanges());
            } finally {
                PostgresTestSupport::resetSharedConnection($connection);
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
            }
        });
    }

    public function test_manual_guards_do_not_reuse_automatic_absent_row_locks(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
            $otherRegistry = new StateTableRegistry([], guardLockAdapter: new PostgresGuardLockAdapter($other));
            $table->withKey('anchor')->expectAbsent();
            $otherRegistry->lock($tableName, new MappedKey(['id' => 'anchor']));
            $connection->beginTransaction();
            try {
                $table->flush();
                $other->transactional(function () use ($other, $otherRegistry): void {
                    $other->executeStatement("SET LOCAL lock_timeout = '50ms'");
                    $otherRegistry->flush();
                });
                self::assertFalse($table->row('anchor'));
            } finally {
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
                $connection->rollBack();
            }
        });
    }

    public function test_manual_guards_require_a_transaction_when_flushed(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $registry = new StateTableRegistry([], guardLockAdapter: new PostgresGuardLockAdapter($connection));
            $registry->lock($tableName, new MappedKey(['id' => 'anchor']));
            self::assertFalse($connection->isTransactionActive());
            $this->expectException(\LogicException::class);
            $registry->flush();
        });
    }

}
