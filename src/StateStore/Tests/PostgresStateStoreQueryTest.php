<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedKey;
use Ultra\StateStore\PostgresGuardLockAdapter;
use Ultra\MappedTable\Tests\PostgresMappedRowSetTestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\StateStore\StateMetadata;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\StateTableRegistry;
use Ultra\Testing\PostgresTestSupport;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final readonly class ManualQueryState
{
    public function __construct(public string $id, public string $status) {}
}

final readonly class ManualIdentityState
{
    public function __construct(public string $id) {}
}

/** @internal */
final class PostgresStateStoreQueryTest extends PostgresMappedRowSetTestCase
{
    public function test_every_manual_guard_is_held_before_queries_are_replayed_and_rows_are_written(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
            $otherRegistry = new StateTableRegistry([], guardLockAdapter: new PostgresGuardLockAdapter($other));
            $metadata = new StateMetadata(ManualQueryState::class, $tableName, $tableName.'#id', 'id', 'string', true, ['id' => 'id'], ['status' => 'status'], ['status' => 'status']);
            $registry = new StateTableRegistry([$metadata->tableKey => $table], guardLockAdapter: new PostgresGuardLockAdapter($connection));
            $store = new StateStore(new StateMetadataRegistry([ManualQueryState::class => $metadata->toArray()]), $registry, new UltraNormalizer());
            $this->insertRow($connection, $tableName, ['id' => 'row-1', 'status' => 'pending']);
            $store->lock(new ManualQueryState('z', 'anchor'));
            $store->lock(new ManualQueryState('a', 'anchor'));
            $observedTransactions = [];
            $store->observeQuery(function () use ($connection, $tableName, $other, $otherRegistry, &$observedTransactions): string {
                $observedTransactions[] = $connection->isTransactionActive();
                if ($connection->isTransactionActive()) {
                    foreach (['a', 'z'] as $id) {
                        $otherRegistry->lock($tableName, new MappedKey(['id' => $id]));
                        $other->beginTransaction();
                        $other->executeStatement("SET LOCAL lock_timeout = '50ms'");
                        try {
                            $otherRegistry->flush();
                            self::fail('All manual guards must already be held at validation time.');
                        } catch (DriverException $exception) {
                            self::assertSame('55P03', $exception->getSQLState());
                        } finally {
                            $other->rollBack();
                            $otherRegistry->clear();
                        }
                    }
                }

                return $connection->fetchOne('SELECT status FROM '.$connection->quoteSingleIdentifier($tableName).' WHERE id = ?', ['row-1']);
            });
            $store->save(new ManualQueryState('row-1', 'confirmed'));
            try {
                $connection->transactional($registry->flush(...));
                self::assertSame([false, true], $observedTransactions);
                self::assertSame('confirmed', $store->load(ManualQueryState::class, 'row-1')->status);
                // Commit releases both guards. A fresh flush on the other connection can acquire them.
                $otherRegistry->lock($tableName, new MappedKey(['id' => 'a']));
                $otherRegistry->lock($tableName, new MappedKey(['id' => 'z']));
                $other->transactional($otherRegistry->flush(...));
            } finally {
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
            }
        });
    }
    public function test_observe_identity_only_state_guards_own_insert_then_committed_presence(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $metadata = new StateMetadata(ManualIdentityState::class, $tableName, $tableName.'#id', 'id', 'string', true, ['id' => 'id'], [], []);
            $registry = new StateTableRegistry([$metadata->tableKey => $table], guardLockAdapter: new PostgresGuardLockAdapter($connection));
            $store = new StateStore(new StateMetadataRegistry([ManualIdentityState::class => $metadata->toArray()]), $registry, new UltraNormalizer());
            self::assertNull($store->observe(ManualIdentityState::class, 'row-1'));
            $store->save(new ManualIdentityState('row-1'));
            self::assertInstanceOf(ManualIdentityState::class, $store->observe(ManualIdentityState::class, 'row-1'));
            $connection->transactional($registry->flush(...));
            $registry->clear();
            self::assertInstanceOf(ManualIdentityState::class, $store->observe(ManualIdentityState::class, 'row-1'));
            self::assertFalse($connection->isTransactionActive());

            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
            try {
                $other->executeStatement('DELETE FROM '.$other->quoteSingleIdentifier($tableName).' WHERE id = ?', ['row-1']);
            } finally {
                $other->close();
            }
            $this->expectException(ConcurrencyException::class);
            $connection->transactional($registry->flush(...));
        });
    }

}
