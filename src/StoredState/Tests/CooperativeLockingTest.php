<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\StateStore\StateMetadata;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateRowSets;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\Tests\Support\StateStoreFixture;
use Ultra\StateStore\Tests\Support\StateStoreSchema;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\Testing\PostgresRowSetTestCase;

final readonly class ManualQueryState
{
    public function __construct(
        public string $id,
        public string $status,
    ) {}
}

/** @internal */
final class CooperativeLockingTest extends PostgresRowSetTestCase
{
    public function test_readers_share_a_guard_and_hold_back_a_writer_until_commit(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            [$table, $registry] = $this->guardedRows($connection, $tableName);
            $guard = $registry->queryGuard('usage');
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            [$otherTable, $otherRegistry] = $this->guardedRows($other, $tableName);
            $otherGuard = $otherRegistry->queryGuard('usage');
            try {
                $phases = [];
                $guard->observe('acme', static function () use ($connection, &$phases): int {
                    $phases[] = $connection->isTransactionActive();

                    return 1;
                });
                $connection->beginTransaction();
                $registry->flush();
                self::assertSame([false, true], $phases);
                $otherGuard->observe('acme', static fn(): int => 1);
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
    public function test_mutations_guard_their_old_and_new_scopes_before_queries_are_replayed(
        string $mutation,
        array $scopes,
    ): void {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName) use (
            $mutation,
            $scopes,
        ): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'old', 'note' => 'value']);
            [$table, $registry] = $this->guardedRows($connection, $tableName);
            if ('insert' === $mutation) {
                $table->withKey('two')->insert()->setColumns(['status' => 'new', 'note' => 'value']);
            } elseif ('move' === $mutation) {
                $table->withKey('one')->expectColumnValue('status', 'old')->setColumn('status', 'new');
            } else {
                $metadata = new StateMetadata(
                    ManualQueryState::class,
                    $tableName,
                    'id',
                    'string',
                    true,
                    ['id' => 'id'],
                    ['status' => 'status'],
                    ['status' => 'status'],
                );
                $store = new StateStore(
                    new StateMetadataRegistry([ManualQueryState::class => $metadata]),
                    new StateRowSets([$tableName => $table], $registry),
                    new UltraNormalizer(),
                );
                $store->observe(ManualQueryState::class, 'one');
                $store->delete(ManualQueryState::class, 'one');
            }
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            [$otherTable, $otherRegistry] = $this->guardedRows($other, $tableName);
            $otherGuard = $otherRegistry->queryGuard('usage');
            try {
                $registry->expectQuery(function () use (
                    $connection,
                    $other,
                    $otherRegistry,
                    $otherGuard,
                    $scopes,
                ): int {
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

    public function test_shared_observation_and_direct_write_are_consolidated_to_exclusive(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            [$table, $combined] = $this->guardedRows($connection, $tableName);
            $guard = $combined->queryGuard('usage');
            $guard->lock('acme');
            $table->withKey('one')->expectColumnValue('status', 'acme')->setColumn('note', 'new');
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            [, $otherRegistry] = $this->guardedRows($other, $tableName);
            $otherGuard = $otherRegistry->queryGuard('usage');
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
            [$table, $registry] = $this->guardedRows($connection, $tableName);
            $registry->queryGuard('usage')->lock('acme');
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            [$otherTable, $otherRegistry] = $this->guardedRows($other, $tableName);
            try {
                $connection->beginTransaction();
                $registry->flush();
                $otherTable->withKey('one')->setColumn('version_a', 'unwatched');
                $this->flushWithTimeout($other, $otherRegistry);
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
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            [$table, $unitOfWork] = $this->guardedRows($connection, $tableName);
            $table->withKey('one')->setColumn('note', 'new');
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('requires an expectation on "status"');
            $connection->transactional($unitOfWork->flush(...));
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
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName) use (
            $mutation,
        ): void {
            $connection->executeStatement('ALTER TABLE ' . $tableName . ' ADD UNIQUE (status, version_a, id)');
            $this->insertRow($connection, $tableName, [
                'id' => 'read',
                'status' => 'acme',
                'version_a' => 'editor',
                'note' => 'old',
            ]);
            [, $reader] = $this->guardedRows($connection, $tableName, collection: true);
            $guard = $reader->queryGuard('collection_usage');
            $guard->observe(
                'acme',
                static fn(): int => (int) $connection->fetchOne('SELECT count(*) FROM ' . $tableName),
            );
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            [$items, $writer] = $this->guardedRows($other, $tableName, collection: true);
            $key = new \Ultra\MappedTable\MappedKey([
                'status' => 'acme',
                'version_a' => 'editor',
                'id' => 'insert' === $mutation ? 'write' : 'read',
            ]);
            match ($mutation) {
                'insert' => $items->withKey($key)->insert()->setColumn('note', 'new'),
                'update' => $items->withKey($key)->expectColumnValue('note', 'old')->setColumn('note', 'new'),
                'delete' => $items->withKey($key)->delete(),
                'empty' => $items->reconcileForOwner(['status' => 'acme', 'version_a' => 'editor'], []),
            };
            try {
                $connection->beginTransaction();
                $reader->flush();
                $this->assertGuardBlocks($other, $writer);
                $connection->commit();
                $this->flushWithTimeout($other, $writer);
                $reader->clear();
                $count = (int) $connection->fetchOne('SELECT count(*) FROM ' . $tableName);
                self::assertSame(
                    match ($mutation) {
                        'insert' => 2,
                        'update' => 1,
                        default => 0,
                    },
                    $count,
                );
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
            }
        });
    }

    /** @return array{MappedRowSet, MappedTableUnitOfWork} */
    private function guardedRows(Connection $connection, string $table, bool $collection = false): array
    {
        $adapter = new PostgresMappedTableAdapter($connection);
        $rows = CooperativeSchema::rows($adapter, $table, $collection);
        $unitOfWork = new MappedTableUnitOfWork([$table => $rows], $adapter, queryGuards: [new QueryGuardDefinition(
            $collection ? 'collection_usage' : 'usage',
            [
                new QueryGuardDependency($table, 'status', ['note']),
            ],
        )]);

        return [$rows, $unitOfWork];
    }

    private function flushWithTimeout(Connection $connection, MappedTableUnitOfWork $registry): void
    {
        $connection->transactional(static function () use ($connection, $registry): void {
            $connection->executeStatement("SET LOCAL lock_timeout = '10ms'");
            $registry->flush();
        });
    }

    private function assertGuardBlocks(Connection $connection, MappedTableUnitOfWork $registry): void
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
        $this->withMappedRowSet(static function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            $otherRegistry = new MappedTableUnitOfWork([], new PostgresMappedTableAdapter($other));
            $registry = new MappedTableUnitOfWork([], new PostgresMappedTableAdapter($connection));
            try {
                // The anchor need not exist, and registering it outside a transaction takes no lock.
                $registry->lockRow($tableName, new MappedKey(['id' => 'anchor']));
                self::assertTrue($registry->hasPendingChanges());
                $otherRegistry->lockRow($tableName, new MappedKey(['id' => 'anchor']));
                $other->transactional($otherRegistry->flush(...));

                $connection->beginTransaction();
                $registry->flush();
                self::assertFalse($registry->hasPendingChanges());
                $otherRegistry->lockRow($tableName, new MappedKey(['id' => 'anchor']));
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
        $this->withMappedRowSet(static function (
            Connection $connection,
            MappedRowSet $table,
            string $tableName,
            MappedTableUnitOfWork $persistence,
        ): void {
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            $otherRegistry = new MappedTableUnitOfWork([], new PostgresMappedTableAdapter($other));
            $table->withKey('anchor')->expectAbsent();
            $otherRegistry->lockRow($tableName, new MappedKey(['id' => 'anchor']));
            $connection->beginTransaction();
            try {
                $persistence->flush();
                $other->transactional(static function () use ($other, $otherRegistry): void {
                    $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
                    $otherRegistry->flush();
                });
                self::assertNull($table->withKey('anchor')->get());
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
                $connection->rollBack();
            }
        });
    }

    public function test_manual_guards_require_a_transaction_when_flushed(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $registry = new MappedTableUnitOfWork([], new PostgresMappedTableAdapter($connection));
            $registry->lockRow($tableName, new MappedKey(['id' => 'anchor']));
            self::assertFalse($connection->isTransactionActive());
            $this->expectException(\LogicException::class);
            $registry->flush();
        });
    }

    public function test_every_manual_guard_is_held_before_queries_are_replayed_and_rows_are_written(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            $otherRegistry = new MappedTableUnitOfWork([], new PostgresMappedTableAdapter($other));
            $metadata = new StateMetadata(
                ManualQueryState::class,
                $tableName,
                'id',
                'string',
                true,
                ['id' => 'id'],
                ['status' => 'status'],
                ['status' => 'status'],
            );
            $adapter = new PostgresMappedTableAdapter($connection);
            $table = CooperativeSchema::rows($adapter, $tableName, false);
            $registry = new MappedTableUnitOfWork([
                $tableName => $table,
            ], $adapter);
            $store = new StateStore(
                new StateMetadataRegistry([ManualQueryState::class => $metadata]),
                new StateRowSets([$tableName => $table], $registry),
                new UltraNormalizer(),
            );
            $this->insertRow($connection, $tableName, ['id' => 'row-1', 'status' => 'pending']);
            $store->lock(new ManualQueryState('z', 'anchor'));
            $store->lock(new ManualQueryState('a', 'anchor'));
            $observedTransactions = [];
            $store->observeQuery(static function () use (
                $connection,
                $tableName,
                $other,
                $otherRegistry,
                &$observedTransactions,
            ): string {
                $observedTransactions[] = $connection->isTransactionActive();
                if ($connection->isTransactionActive()) {
                    foreach (['a', 'z'] as $id) {
                        $otherRegistry->lockRow($tableName, new MappedKey(['id' => $id]));
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

                return $connection->fetchOne(
                    'SELECT status FROM ' . $connection->quoteSingleIdentifier($tableName) . ' WHERE id = ?',
                    ['row-1'],
                );
            });
            $store->save(new ManualQueryState('row-1', 'confirmed'));
            try {
                $connection->transactional($registry->flush(...));
                self::assertSame([false, true], $observedTransactions);
                self::assertSame('confirmed', $store->load(ManualQueryState::class, 'row-1')->status);
                // Commit releases both guards. A fresh flush on the other connection can acquire them.
                $otherRegistry->lockRow($tableName, new MappedKey(['id' => 'a']));
                $otherRegistry->lockRow($tableName, new MappedKey(['id' => 'z']));
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
            $fixture->unitOfWork->clear();
            $definitions = [new QueryGuardDefinition('usage', [
                new QueryGuardDependency(StateStoreSchema::ORDERS, 'order_id', []),
                new QueryGuardDependency(StateStoreSchema::ORDER_LINES, 'order_id'),
            ])];
            [, $reader] = $fixture->newStore([OrderWithLinesState::class], guards: $definitions);
            $reader->queryGuard('usage')->lock('one');
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            $previousSearchPath = $other->fetchOne('SHOW search_path');
            try {
                $other->executeStatement("SELECT set_config('search_path', ?, false)", [$fixture->storage->fetchOne(
                    'SHOW search_path',
                )]);
                [$store, $writer] = $fixture->newStore(
                    [OrderWithLinesState::class],
                    guards: $definitions,
                    storage: $other,
                );
                $store->observe(OrderWithLinesState::class, 'one');
                $store->delete(OrderWithLinesState::class, 'one');
                $fixture->storage->beginTransaction();
                $reader->flush();
                try {
                    $other->transactional(static function () use ($other, $writer): void {
                        $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
                        $writer->flush();
                    });
                    static::fail('The deletion must be excluded while the reader holds the shared guard.');
                } catch (DriverException $exception) {
                    static::assertSame('55P03', $exception->getSQLState());
                }
                static::assertNotNull($fixture->store->load(OrderWithLinesState::class, 'one'));
                $fixture->storage->commit();
                $other->transactional($writer->flush(...));
                static::assertNull($fixture->store->load(OrderWithLinesState::class, 'one'));
                static::assertSame(
                    [],
                    $fixture->collectionRows(
                        $fixture->metadata->metadataFor(OrderWithLinesState::class)->collections['lines'],
                        'one',
                    ),
                );
            } finally {
                PostgresConnectionSupport::resetSharedConnection($other);
                $other->executeStatement("SELECT set_config('search_path', ?, false)", [$previousSearchPath]);
            }
        } finally {
            $fixture->close();
        }
    }
}

final class CooperativeSchema
{
    public static function rows(PostgresMappedTableAdapter $adapter, string $table, bool $collection): MappedRowSet
    {
        $schema = new SchemaDefinition([
            new TableDefinition(
                $table,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('status', type: ColumnType::Text),
                    new ColumnDefinition('version_a', type: ColumnType::Text, nullable: !$collection),
                    new ColumnDefinition('note', type: ColumnType::Text, nullable: true),
                ],
                primaryKey: $collection ? ['id', 'status', 'version_a'] : ['id'],
            ),
        ]);

        return $adapter->compileRowSet($schema, $table, ownerColumns: $collection ? ['status', 'version_a'] : [])();
    }
}
