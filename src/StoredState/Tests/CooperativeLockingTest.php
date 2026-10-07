<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\Testing\PostgresRowSetTestCase;
use Ultra\StateStore\PostgresGuardLockAdapter;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\QueryGuardDefinition;
use Ultra\StateStore\StateTableRegistry;
use Ultra\StateStore\Tests\Support\StateStoreFixture;
use Ultra\Testing\PostgresConnectionSupport;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\StateStore\GuardLockMode;
use Ultra\StateStore\InMemoryGuardLockAdapter;
use Ultra\Mapper\UltraNormalizer;
use Ultra\StateStore\StateMetadata;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;

final readonly class ManualQueryState
{
    public function __construct(public string $id, public string $status) {}
}

/** @internal */
final class CooperativeLockingTest extends PostgresRowSetTestCase
{
    public function test_readers_share_a_guard_and_hold_back_a_writer_until_commit(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            $registry = $this->registry($connection, $table);
            $guard = new QueryGuard($registry, 'usage');
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $otherTable = new MappedRowSet(new PostgresMappedRowSetAdapter($other, $tableName));
            $otherRegistry = $this->registry($other, $otherTable);
            $otherGuard = new QueryGuard($otherRegistry, 'usage');
            try {
                $phases = [];
                $guard->observe('acme', function () use ($connection, &$phases): int {
                    $phases[] = $connection->isTransactionActive();

                    return 1;
                });
                $connection->beginTransaction();
                $registry->flush();
                self::assertSame([false, true], $phases);
                $otherGuard->observe('acme', static fn (): int => 1);
                $this->flushWithTimeout($other, $otherRegistry);
                $otherRegistry->clear();

                // This writer never calls the guarded query. Its plan must still participate.
                $otherTable->withKey('one')->expectColumnValue('status', 'acme')->setColumn('note', 'new');
                $this->assertGuardBlocks($other, $otherRegistry);
                $connection->commit();
                $this->flushWithTimeout($other, $otherRegistry);
                self::assertSame('new', $this->fetchRow($connection, $tableName, 'one')['note']);
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    public static function mutations(): iterable
    {
        yield 'insert' => ['insert', ['new']];
        yield 'move' => ['move', ['old', 'new']];
        yield 'delete' => ['delete', ['old']];
    }

    #[DataProvider('mutations')]
    public function test_mutations_guard_their_old_and_new_scopes_before_queries_are_replayed(string $mutation, array $scopes): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName) use ($mutation, $scopes): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'old', 'note' => 'value']);
            $registry = $this->registry($connection, $table);
            if ('insert' === $mutation) {
                $table->withKey('two')->insert()->setColumns(['status' => 'new', 'note' => 'value']);
            } elseif ('move' === $mutation) {
                $table->withKey('one')->expectColumnValue('status', 'old')->setColumn('status', 'new');
            } else {
                $metadata = new StateMetadata(ManualQueryState::class, $tableName, 'test', 'id', 'string', true,
                    ['id' => 'id'], ['status' => 'status'], ['status' => 'status']);
                $store = new StateStore(new StateMetadataRegistry([ManualQueryState::class => $metadata]), $registry, new UltraNormalizer());
                $store->observe(ManualQueryState::class, 'one');
                $store->delete(ManualQueryState::class, 'one');
            }
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $otherTable = new MappedRowSet(new PostgresMappedRowSetAdapter($other, $tableName));
            $otherRegistry = $this->registry($other, $otherTable);
            $otherGuard = new QueryGuard($otherRegistry, 'usage');
            try {
                $registry->expectQuery(function () use ($connection, $other, $otherRegistry, $otherGuard, $scopes): int {
                    if ($connection->isTransactionActive()) {
                        foreach ($scopes as $scope) {
                            $otherGuard->lock($scope);
                            $this->assertGuardBlocks($other, $otherRegistry);
                            $otherRegistry->clear();
                        }
                        $otherGuard->lock('unrelated');
                        $this->flushWithTimeout($other, $otherRegistry);
                    }

                    return 1;
                }, 1);
                $connection->transactional($registry->flush(...));
                foreach ($scopes as $scope) {
                    $otherGuard->lock($scope);
                }
                $this->flushWithTimeout($other, $otherRegistry);
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    public function test_shared_observation_and_write_are_consolidated_to_exclusive_across_table_views(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            $view = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, $tableName));
            $combined = new StateTableRegistry(['view' => $view, 'test' => $table], queryGuardDefinitions: $this->definitions(), guardLockAdapter: new PostgresGuardLockAdapter($connection));
            $guard = new QueryGuard($combined, 'usage');
            $guard->lock('acme');
            $table->withKey('one')->expectColumnValue('status', 'acme')->setColumn('note', 'new');
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $otherRegistry = $this->registry($other, new MappedRowSet(new PostgresMappedRowSetAdapter($other, $tableName)));
            $otherGuard = new QueryGuard($otherRegistry, 'usage');
            try {
                $combined->expectQuery(function () use ($other, $otherRegistry, $otherGuard): int {
                    $otherGuard->lock('acme');
                    $this->assertGuardBlocks($other, $otherRegistry);

                    return 1;
                }, 1);
                $connection->transactional($combined->flush(...));
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    public function test_changes_to_unwatched_columns_do_not_block_on_a_query_guard(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            $registry = $this->registry($connection, $table);
            (new QueryGuard($registry, 'usage'))->lock('acme');
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $otherTable = new MappedRowSet(new PostgresMappedRowSetAdapter($other, $tableName));
            try {
                $connection->beginTransaction();
                $registry->flush();
                $otherTable->withKey('one')->setColumn('version_a', 'unwatched');
                $this->flushWithTimeout($other, $this->registry($other, $otherTable));
                self::assertSame('unwatched', $this->fetchRow($connection, $tableName, 'one')['version_a']);
                $connection->commit();
                $registry->clear();
                self::assertFalse($registry->hasPendingChanges());
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    public function test_a_mutation_without_an_observed_scope_is_rejected(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table): void {
            $table->withKey('one')->setColumn('note', 'new');
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('requires observing column "status"');
            $connection->transactional($this->registry($connection, $table)->flush(...));
        });
    }

    public static function collectionMutations(): iterable
    {
        foreach (['insert', 'update', 'delete', 'empty'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[DataProvider('collectionMutations')]
    public function test_collection_writers_participate_without_calling_the_guarded_query(string $mutation): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName) use ($mutation): void {
            $connection->executeStatement('ALTER TABLE '.$tableName.' ADD UNIQUE (status, version_a, id)');
            $this->insertRow($connection, $tableName, ['id' => 'read', 'status' => 'acme', 'version_a' => 'editor', 'note' => 'old']);
            $collection = new \Ultra\StateStore\StateCollectionMetadata('permissions', $tableName, 'permissions', false,
                ['tenantId' => 'status', 'roleId' => 'version_a'], \stdClass::class, 'id', true, ['id' => 'id'], ['note' => 'note']);
            $state = new \Ultra\StateStore\StateMetadata(\stdClass::class, $tableName, 'root', 'id', \stdClass::class, false,
                ['tenantId' => 'status', 'roleId' => 'version_a'], [], [], collections: ['permissions' => $collection]);
            $definition = new \Ultra\StateStore\QueryGuardDefinition();
            $definition->watch(\stdClass::class, scopeBy: 'id.tenantId')->whenPropertiesChange('permissions');
            $definitions = ['collection_usage' => $definition->compile(new \Ultra\StateStore\StateMetadataRegistry([\stdClass::class => $state]))];
            $reader = new StateTableRegistry(['root' => $table], queryGuardDefinitions: $definitions, guardLockAdapter: new PostgresGuardLockAdapter($connection));
            $guard = new QueryGuard($reader, 'collection_usage');
            $guard->observe('acme', fn (): int => (int) $connection->fetchOne('SELECT count(*) FROM '.$tableName));
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $writer = new StateTableRegistry([], new \Ultra\MappedTable\PostgresMappedRowSetAdapterFactory($other), $definitions, new PostgresGuardLockAdapter($other));
            $items = $writer->collectionFor($collection, ['tenantId' => 'acme', 'roleId' => 'editor']);
            $key = new \Ultra\MappedTable\MappedKey(['status' => 'acme', 'version_a' => 'editor', 'id' => 'insert' === $mutation ? 'write' : 'read']);
            match ($mutation) {
                'insert' => $items->withKey($key)->insert()->setColumn('note', 'new'),
                'update' => $items->withKey($key)->expectColumnValue('note', 'old')->setColumn('note', 'new'),
                'delete' => $items->withKey($key)->delete(),
                'empty' => $items->reconcileRows([]),
            };
            try {
                $connection->beginTransaction();
                $reader->flush();
                $this->assertGuardBlocks($other, $writer);
                $connection->commit();
                $this->flushWithTimeout($other, $writer);
                $reader->clear();
                $count = (int) $connection->fetchOne('SELECT count(*) FROM '.$tableName);
                self::assertSame(match ($mutation) { 'insert' => 2, 'update' => 1, default => 0 }, $count);
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    private function registry(Connection $connection, MappedRowSet $table): StateTableRegistry
    {
        return new StateTableRegistry(['test' => $table], queryGuardDefinitions: $this->definitions(), guardLockAdapter: new PostgresGuardLockAdapter($connection));
    }

    private function definitions(): array
    {
        return ['usage' => [['tableKey' => 'test', 'keyBy' => 'status', 'columns' => ['note']]]];
    }

    private function flushWithTimeout(Connection $connection, StateTableRegistry $registry): void
    {
        $connection->transactional(function () use ($connection, $registry): void {
            $connection->executeStatement("SET LOCAL lock_timeout = '10ms'");
            $registry->flush();
        });
    }

    private function assertGuardBlocks(Connection $connection, StateTableRegistry $registry): void
    {
        try {
            $this->flushWithTimeout($connection, $registry);
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState());

            return;
        }
        self::fail('The query guard should block this competing flush.');
    }

    public function test_manual_locks_are_deferred_and_held_until_the_transaction_ends(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
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
                $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
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
                PostgresConnectionSupport::resetSharedConnection($connection);
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    public function test_manual_guards_do_not_reuse_automatic_absent_row_locks(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $otherRegistry = new StateTableRegistry([], guardLockAdapter: new PostgresGuardLockAdapter($other));
            $table->withKey('anchor')->expectAbsent();
            $otherRegistry->lock($tableName, new MappedKey(['id' => 'anchor']));
            $connection->beginTransaction();
            try {
                $table->flush();
                $other->transactional(function () use ($other, $otherRegistry): void {
                    $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
                    $otherRegistry->flush();
                });
                self::assertFalse($table->row('anchor'));
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
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

    public static function modes(): iterable
    {
        yield 'shared' => [GuardLockMode::SHARED];
        yield 'exclusive' => [GuardLockMode::EXCLUSIVE];
    }

    #[DataProvider('modes')]
    public function test_acquisition_requires_an_active_transaction(GuardLockMode $mode): void
    {
        $adapter = new InMemoryGuardLockAdapter(new InMemoryRowDatabase());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('active transaction');
        $adapter->acquire('usage', 'company', $mode);
    }

    public function test_every_manual_guard_is_held_before_queries_are_replayed_and_rows_are_written(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
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
                        $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
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
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    public function test_owner_cleanup_is_excluded_by_a_query_reader_and_can_be_retried_after_commit(): void
    {
        $fixture = StateStoreFixture::forBackend('postgres', [OrderWithLinesState::class]);
        try {
            $fixture->store->save(new OrderWithLinesState('one', 'pending', [new OrderLineState('line', 'SKU', 1)]));
            $fixture->flush();
            $fixture->registry->clear();
            $definition = new QueryGuardDefinition();
            $definition->watch(OrderWithLinesState::class, scopeBy: 'orderId')->whenPropertiesChange('lines');
            $definitions = ['usage' => $definition->compile($fixture->metadata)];
            [, $reader] = $fixture->newStore([OrderWithLinesState::class], $definitions);
            (new QueryGuard($reader, 'usage'))->lock('one');
            $other = PostgresConnectionSupport::sharedConnection((string) getenv('ULTRA_TEST_POSTGRES_DSN'), self::class);
            $previousSearchPath = $other->fetchOne('SHOW search_path');
            try {
                $other->executeStatement("SELECT set_config('search_path', ?, false)", [$fixture->storage->fetchOne('SHOW search_path')]);
                [$store, $writer] = $fixture->newStore([OrderWithLinesState::class], $definitions, $other);
                $store->observe(OrderWithLinesState::class, 'one');
                $store->delete(OrderWithLinesState::class, 'one');
                $fixture->storage->beginTransaction();
                $reader->flush();
                try {
                    $other->transactional(function () use ($other, $writer): void {
                        $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
                        $writer->flush();
                    });
                    self::fail('The deletion must be excluded while the reader holds the shared guard.');
                } catch (DriverException $exception) {
                    self::assertSame('55P03', $exception->getSQLState());
                }
                self::assertNotNull($fixture->store->load(OrderWithLinesState::class, 'one'));
                $fixture->storage->commit();
                $other->transactional($writer->flush(...));
                self::assertNull($fixture->store->load(OrderWithLinesState::class, 'one'));
                self::assertSame([], $fixture->collectionRows($fixture->metadata->metadataFor(OrderWithLinesState::class)->collections['lines'], 'one'));
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
                $other->executeStatement("SELECT set_config('search_path', ?, false)", [$previousSearchPath]);
            }
        } finally {
            $fixture->close();
        }
    }
}
