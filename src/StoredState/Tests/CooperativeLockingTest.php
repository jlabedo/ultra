<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresGuardLockAdapter;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\Mapper\UltraNormalizer;
use Ultra\StateStore\StateMetadata;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateRowSets;
use Ultra\StateStore\StateStore;
use Ultra\StateStore\Tests\Support\OrderLinesDefinition;
use Ultra\StateStore\Tests\Support\OrdersDefinition;
use Ultra\StateStore\Tests\Support\StateStoreFixture;
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
        $mapping = new CooperativeRowsDefinition()->mappingFor($table, $collection);
        $rows = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, $table, $mapping->keyColumns), $mapping);
        $unitOfWork = new MappedTableUnitOfWork(
            [CooperativeRowsDefinition::class => $rows],
            queryGuards: [new QueryGuardDefinition($collection ? 'collection_usage' : 'usage', [
                new QueryGuardDependency(CooperativeRowsDefinition::class, 'status', ['note']),
            ])],
            guardLocks: new PostgresGuardLockAdapter($connection),
        );

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
            $otherRegistry = new MappedTableUnitOfWork([], guardLocks: new PostgresGuardLockAdapter($other));
            $registry = new MappedTableUnitOfWork([], guardLocks: new PostgresGuardLockAdapter($connection));
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
        $this->withMappedRowSet(static function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $other = PostgresConnectionSupport::sharedConnection(
                (string) getenv('ULTRA_TEST_POSTGRES_DSN'),
                self::class,
            );
            $otherRegistry = new MappedTableUnitOfWork([], guardLocks: new PostgresGuardLockAdapter($other));
            $table->withKey('anchor')->expectAbsent();
            $otherRegistry->lockRow($tableName, new MappedKey(['id' => 'anchor']));
            $connection->beginTransaction();
            try {
                $table->flush();
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
            $registry = new MappedTableUnitOfWork([], guardLocks: new PostgresGuardLockAdapter($connection));
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
            $otherRegistry = new MappedTableUnitOfWork([], guardLocks: new PostgresGuardLockAdapter($other));
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
            $mapping = new CooperativeRowsDefinition()->mappingFor($tableName, collection: false);
            $table = new MappedRowSet(new PostgresMappedRowSetAdapter($connection, $tableName), $mapping);
            $registry = new MappedTableUnitOfWork([
                CooperativeRowsDefinition::class => $table,
            ], guardLocks: new PostgresGuardLockAdapter($connection));
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
                new QueryGuardDependency(OrdersDefinition::class, 'order_id', []),
                new QueryGuardDependency(OrderLinesDefinition::class, 'order_id'),
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

final class CooperativeRowsDefinition implements MappedRowSetDefinition
{
    public function mapping(): MappedRowSetMapping
    {
        return $this->mappingFor('cooperative_test_rows', false);
    }

    public function mappingFor(string $table, bool $collection): MappedRowSetMapping
    {
        return new MappedRowSetMapping(
            $table,
            [
                'id' => new MappedColumn('string'),
                'status' => new MappedColumn('string'),
                'version_a' => new MappedColumn('string', nullable: !$collection),
                'note' => new MappedColumn('string', nullable: true),
            ],
            $collection ? ['id', 'status', 'version_a'] : ['id'],
            ownerColumns: $collection ? ['status', 'version_a'] : [],
        );
    }
}
