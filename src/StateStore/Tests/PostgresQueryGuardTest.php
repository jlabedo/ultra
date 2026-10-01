<?php

declare(strict_types=1);

namespace Ultra\StateStore\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\MappedTable\Tests\PostgresMappedRowSetTestCase;
use Ultra\StateStore\PostgresGuardLockAdapter;
use Ultra\StateStore\QueryGuard;
use Ultra\StateStore\StateTableRegistry;
use Ultra\Testing\PostgresTestSupport;

/** @internal */
final class PostgresQueryGuardTest extends PostgresMappedRowSetTestCase
{
    public function test_readers_share_a_guard_and_hold_back_a_writer_until_commit(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            $registry = $this->registry($connection, $table);
            $guard = new QueryGuard($registry, 'usage');
            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
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
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
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
                $table->withKey('one')->expectColumnValue('status', 'old')->delete();
            }
            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
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
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
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
            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
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
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
            }
        });
    }

    public function test_changes_to_unwatched_columns_do_not_block_on_a_query_guard(): void
    {
        $this->withMappedRowSet(function (Connection $connection, MappedRowSet $table, string $tableName): void {
            $this->insertRow($connection, $tableName, ['id' => 'one', 'status' => 'acme', 'note' => 'old']);
            $registry = $this->registry($connection, $table);
            (new QueryGuard($registry, 'usage'))->lock('acme');
            $other = PostgresTestSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
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
                PostgresTestSupport::resetSharedConnection($other);
                $other->close();
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
            $connection->executeStatement("SET LOCAL lock_timeout = '50ms'");
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
}
