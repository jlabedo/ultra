<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class PersistenceLockingTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function competingWrites(): iterable
    {
        yield 'checked row versus update' => ['present', 'update', true];
        yield 'checked row without absence protection versus update' => ['present', 'update', false];
        yield 'absent row versus insert' => ['absent', 'insert', true];
        yield 'absent row versus upsert' => ['absent', 'upsert', true];
    }

    #[DataProvider('competingWrites')]
    public function test_a_flushed_observation_blocks_competing_writes_until_its_transaction_ends(
        string $expectation,
        string $operation,
        bool $allowAbsentObservations,
    ): void {
        $fixture = $this->fixture('postgres', allowAbsentObservations: $allowAbsentObservations);
        $connection = $fixture->connection();
        if ('present' === $expectation) {
            $fixture->seed(['id' => 'row', 'status' => 'pending']);
            $fixture->rows->withKey('row')->expectColumnValue('status', 'pending');
        } else {
            $fixture->rows->withKey('row')->expectAbsent();
        }
        $other = PostgresConnectionSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
        $competing = new MappedRowSet(
            new PostgresMappedRowSetAdapter(
                $other,
                $fixture->tableName,
                policy: new MappedTablePolicy($allowAbsentObservations),
            ),
        );
        if ('update' === $operation) {
            $competing->withKey('row')->setColumn('status', 'paid');
        } else {
            $competing->withKey('row')->{$operation}()->setColumn('status', 'paid');
        }
        $connection->beginTransaction();
        try {
            $fixture->rows->flush();
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
            try {
                $competing->flush();
                static::fail('The competing write must wait for the observation transaction.');
            } catch (DriverException $exception) {
                static::assertStringContainsString('lock timeout', $exception->getMessage());
            } finally {
                $other->rollBack();
            }
            $connection->commit();
            $other->transactional($competing->flush(...));
            static::assertSame(['status' => 'paid'], $fixture->stored('row', ['status']));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }
            $other->close();
        }
    }

    /** @return iterable<string, array{bool, bool, string}> */
    public static function absenceProtectionPolicies(): iterable
    {
        foreach ([true, false] as $protected) {
            foreach ([true, false] as $scoped) {
                foreach (['insert', 'upsert'] as $operation) {
                    yield implode(' ', [
                        $protected ? 'protected' : 'unprotected',
                        $scoped ? 'scoped' : 'unscoped',
                        $operation,
                    ]) => [$protected, $scoped, $operation];
                }
            }
        }
    }

    #[DataProvider('absenceProtectionPolicies')]
    public function test_table_policy_controls_creation_locks_for_all_views(
        bool $protected,
        bool $scoped,
        string $operation,
    ): void {
        $fixture = $this->fixture('postgres', ['tenant', 'id']);
        $connection = $fixture->connection();
        $factory = new PostgresMappedRowSetAdapterFactory($connection, tablePolicies: [
            $protected ? 'another_table' : $fixture->tableName => new MappedTablePolicy(allowAbsentObservations: false),
        ]);
        $scope = new MappedKey(['tenant' => 'tenant-1']);
        $rows = new MappedRowSet(
            $scoped
                ? $factory->createScoped($fixture->tableName, ['tenant', 'id'], $scope)
                : $factory->create($fixture->tableName, ['tenant', 'id']),
        );
        foreach (['first', 'second'] as $id) {
            $rows
                ->withKey(new MappedKey(['tenant' => 'tenant-1', 'id' => $id]))
                ->{$operation}()
                ->setColumn('status', 'new');
        }

        $connection->beginTransaction();
        try {
            $rows->flush();
            static::assertSame(2, (int) $connection->fetchOne('SELECT count(*) FROM ' . $fixture->tableName));
            static::assertSame(
                $protected ? 2 : 0,
                (int) $connection->fetchOne(
                    "SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory'",
                ),
            );
        } finally {
            $connection->rollBack();
        }
    }

    public function test_disabled_absence_protection_preserves_row_set_expectations(): void
    {
        $fixture = $this->fixture('postgres', allowAbsentObservations: false);
        $fixture->rows->expectSameRowKeys([]);
        $fixture->connection()->beginTransaction();
        try {
            $fixture->rows->flush();
            static::assertSame(
                1,
                (int) $fixture
                    ->connection()
                    ->fetchOne("SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory'"),
            );
        } finally {
            $fixture->connection()->rollBack();
        }
        $fixture->seed(['id' => 'concurrent']);
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    /** @return iterable<string, array{string}> */
    public static function transactionScopedObservations(): iterable
    {
        yield 'absence' => ['absence'];
        yield 'membership' => ['membership'];
    }

    #[DataProvider('transactionScopedObservations')]
    public function test_postgres_observation_locks_require_an_active_transaction(string $expectation): void
    {
        $fixture = $this->fixture('postgres');
        if ('absence' === $expectation) {
            $fixture->rows->withKey('row')->expectAbsent();
        } else {
            $fixture->rows->expectSameRowKeys([]);
        }
        $this->expectException(\LogicException::class);
        $fixture->rows->flush();
    }
}
