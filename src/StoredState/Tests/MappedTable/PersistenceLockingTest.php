<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedTablePolicy;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Testing\PostgresConnectionSupport;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class PersistenceLockingTest extends RowSetTestCase
{
    public function test_competing_postgres_upserts_add_their_deltas_to_the_row_created_by_another_writer(): void
    {
        $fixture = $this->fixture(
            'postgres',
            columnTypes: [
                'units' => new ColumnDefinition('units', type: ColumnType::Int64),
            ],
            allowAbsentObservations: false,
        );
        $connection = $fixture->connection();
        $other = PostgresConnectionSupport::connection((string) getenv('ULTRA_TEST_POSTGRES_DSN'));
        $adapter = new PostgresMappedTableAdapter($other, tablePolicies: [
            $fixture->tableName => new MappedTablePolicy(allowAbsentObservations: false),
        ]);
        $competing = $fixture->newRows($adapter);
        $writer = new MappedTableUnitOfWork([$fixture->tableName => $competing], $adapter);
        $fixture->rows->withKey('row')->upsert()->incrementColumn('units', 10);
        $competing->withKey('row')->upsert()->incrementColumn('units', -3);
        $connection->beginTransaction();
        try {
            $fixture->unit()->flush();
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
            try {
                $writer->flush();
                static::fail('The competing upsert must wait for the insertion.');
            } catch (DriverException $exception) {
                static::assertSame('55P03', $exception->getSQLState());
            } finally {
                $other->rollBack();
            }
            $connection->commit();
            $other->transactional($writer->flush(...));

            static::assertSame(['units' => 7], $fixture->stored('row', ['units']));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $other->close();
        }
    }

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
        $adapter = new PostgresMappedTableAdapter($other, tablePolicies: [
            $fixture->tableName => new MappedTablePolicy($allowAbsentObservations),
        ]);
        $competing = $fixture->newRows($adapter);
        $writer = new MappedTableUnitOfWork([$fixture->tableName => $competing], $adapter);
        if ('update' === $operation) {
            $competing->withKey('row')->setColumn('status', 'paid');
        } else {
            $competing->withKey('row')->{$operation}()->setColumn('status', 'paid');
        }
        $connection->beginTransaction();
        try {
            $fixture->unit()->flush();
            $other->beginTransaction();
            $other->executeStatement("SET LOCAL lock_timeout = '10ms'");
            try {
                $writer->flush();
                static::fail('The competing write must wait for the observation transaction.');
            } catch (DriverException $exception) {
                static::assertStringContainsString('lock timeout', $exception->getMessage());
            } finally {
                $other->rollBack();
            }
            $connection->commit();
            $other->transactional($writer->flush(...));
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

    /** @return iterable<string, array{bool, string}> */
    public static function absenceProtectionPolicies(): iterable
    {
        foreach ([true, false] as $protected) {
            foreach (['insert', 'upsert'] as $operation) {
                yield ($protected ? 'protected' : 'unprotected') . ' ' . $operation => [$protected, $operation];
            }
        }
    }

    #[DataProvider('absenceProtectionPolicies')]
    public function test_table_policy_controls_creation_locks(bool $protected, string $operation): void
    {
        $fixture = $this->fixture('postgres', ['tenant', 'id']);
        $connection = $fixture->connection();
        $factory = new PostgresMappedTableAdapter($connection, tablePolicies: [
            $protected ? 'another_table' : $fixture->tableName => new MappedTablePolicy(allowAbsentObservations: false),
        ]);
        $rows = $fixture->newRows($factory);
        $unit = new MappedTableUnitOfWork([$fixture->tableName => $rows], $factory);
        foreach (['first', 'second'] as $id) {
            $rows
                ->withKey(new MappedKey(['tenant' => 'tenant-1', 'id' => $id]))
                ->{$operation}()
                ->setColumn('status', 'new');
        }

        $connection->beginTransaction();
        try {
            $unit->flush();
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
            $fixture->unit()->flush();
            static::assertSame(
                1,
                (int) $fixture
                    ->connection()
                    ->fetchOne("SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory'"),
            );
        } finally {
            $fixture->connection()->rollBack();
        }
        $fixture->rows->clear();
        $fixture->rows->expectSameRowKeys([]);
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
        $fixture->unit()->flush();
    }
}
