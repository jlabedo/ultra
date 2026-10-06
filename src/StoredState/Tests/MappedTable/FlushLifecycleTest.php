<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\MappedTable\Tests\Support\RecordingRowSetAdapter;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class FlushLifecycleTest extends RowSetTestCase
{
    public function test_preparation_performs_no_io_and_flush_uses_a_sorted_immutable_snapshot(): void
    {
        $adapter = new RecordingRowSetAdapter();
        $rows = new MappedRowSet($adapter);
        self::assertFalse($rows->hasPendingChanges());
        $rows->reconcileRows([
            new MappedRow(new MappedKey(['id' => 'second']), ['status' => 'second']),
            new MappedRow(new MappedKey(['id' => 'first']), ['status' => 'first']),
        ]);
        $snapshot = $rows->updatePlan();
        self::assertTrue($rows->hasPendingChanges());
        self::assertSame(0, $adapter->reads);
        self::assertSame([], $adapter->batches);
        $rows->withKey('first')->setColumn('status', 'changed');
        $rows->flush();
        self::assertCount(1, $adapter->batches);
        self::assertSame(['first', 'second'], array_map(static fn ($plan) => $plan->key->value('id'), $adapter->batches[0]->rowUpdates));
        self::assertSame(['status' => 'changed'], $adapter->batches[0]->rowUpdates[0]->setColumns);
        self::assertTrue($rows->hasPendingChanges());
        $rows->clear();
        self::assertSame(['status' => 'first'], $snapshot->rowUpdates[0]->setColumns);
        self::assertSame(['first', 'second'], array_map(static fn ($key) => $key->value('id'), $snapshot->retainedRowKeys));
        self::assertFalse($rows->hasPendingChanges());
    }

    public function test_observations_and_unchanged_updates_flush_as_checks_without_writes(): void
    {
        $adapter = new RecordingRowSetAdapter();
        $rows = new MappedRowSet($adapter);
        $rows->withKey('absent')->expectAbsent();
        $rows->withKey('present')->expectPresent();
        $rows->withKey('unchanged')->expectColumnValues(['status' => 'pending', 'note' => null])->setColumns(['status' => 'pending', 'note' => null]);
        $rows->withKey('changed')->expectColumnValue('status', 'pending')->setColumn('status', 'paid');
        $rows->flush();
        $plans = $adapter->batches[0]->rowUpdates;
        self::assertSame([RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::MODE_UPDATE, RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::MODE_EXPECT], array_column($plans, 'mode'));
        self::assertSame([false, true, false, false], array_map(static fn ($plan) => $plan->writes(), $plans));
        self::assertSame([], $plans[3]->setColumns);
        self::assertSame(['status' => 'pending', 'note' => null], $plans[3]->expectedColumns);
    }

    #[DataProvider('backends')]
    public function test_clear_discards_all_local_writes_membership_and_expectations(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'existing', 'status' => 'pending']);
        $rows = $fixture->rows;
        $rows->expectSameRowKeys(['existing']);
        $rows->withKey('existing')->expectColumnValue('status', 'pending')->setColumn('status', 'changed');
        $rows->withKey('added')->expectAbsent()->setColumn('status', 'new');
        $rows->expectSameRowKeys(['existing', 'added']);
        $rows->reconcileRows([]);
        self::assertTrue($rows->hasPendingChanges());
        self::assertSame([], $rows->rows());
        $rows->clear();
        self::assertFalse($rows->hasPendingChanges());
        self::assertSame(['status' => 'pending'], $rows->row('existing', ['status']));
        $fixture->seed(['id' => 'existing', 'status' => 'concurrent']);
        $fixture->seed(['id' => 'added', 'status' => 'other']);
        $fixture->flush();
        self::assertSame(['status' => 'concurrent'], $fixture->stored('existing', ['status']));
        self::assertSame(['status' => 'other'], $fixture->stored('added', ['status']));
    }

    #[DataProvider('backends')]
    public function test_unchanged_updates_still_reject_concurrent_value_changes(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'row', 'status' => 'pending']);
        $fixture->rows->withKey('row')->expectColumnValue('status', 'pending')->setColumn('status', 'pending');
        $fixture->seed(['id' => 'row', 'status' => 'concurrent']);
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    public function test_unchanged_updates_do_not_rewrite_the_postgres_row(): void
    {
        $fixture = $this->fixture('postgres');
        $fixture->seed(['id' => 'row', 'status' => 'pending']);
        $connection = $fixture->connection();
        $sql = 'SELECT xmin::text FROM '.$connection->quoteSingleIdentifier($fixture->tableName);
        $before = $connection->fetchOne($sql);
        $fixture->rows->withKey('row')->expectColumnValue('status', 'pending')->setColumn('status', 'pending');
        $fixture->flush();
        self::assertSame($before, $connection->fetchOne($sql));
    }

    #[DataProvider('backends')]
    public function test_external_transactions_roll_back_partial_writes_on_a_conflict(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'first', 'status' => 'pending']);
        $fixture->seed(['id' => 'second', 'status' => 'pending']);
        $fixture->rows->withKey('first')->expectColumnValue('status', 'pending')->setColumn('status', 'active');
        $fixture->rows->withKey('second')->expectColumnValue('status', 'stale')->setColumn('status', 'active');
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            self::assertSame(['status' => 'pending'], $fixture->stored('first', ['status']));
            self::assertSame(['status' => 'pending'], $fixture->stored('second', ['status']));
            self::assertFalse($fixture->storage->isTransactionActive());
            self::assertTrue($fixture->rows->hasPendingChanges());
        }
    }

    public function test_flush_itself_does_not_open_or_roll_back_a_transaction(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'first', 'status' => 'pending']);
        $fixture->seed(['id' => 'second', 'status' => 'pending']);
        $fixture->rows->withKey('first')->setColumn('status', 'active');
        $fixture->rows->withKey('second')->expectColumnValue('status', 'stale')->setColumn('status', 'active');
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->rows->flush();
        } finally {
            self::assertSame(['status' => 'active'], $fixture->stored('first', ['status']));
            self::assertSame(['status' => 'pending'], $fixture->stored('second', ['status']));
            self::assertFalse($fixture->storage->isTransactionActive());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function transactionEndings(): iterable
    {
        yield 'commit' => ['commit'];
        yield 'rollback' => ['rollBack'];
    }

    #[DataProvider('transactionEndings')]
    public function test_in_memory_transactions_keep_commits_and_restore_rollbacks(string $finish): void
    {
        $database = new InMemoryRowDatabase();
        $database->seedRow('rows', 'id', ['id' => 'row', 'status' => 'pending']);
        $database->beginTransaction();
        self::assertTrue($database->isTransactionActive());
        $database->writeRow('rows', 'id', ['id' => 'row', 'status' => 'active']);
        $database->{$finish}();
        self::assertSame('commit' === $finish ? 'active' : 'pending', $database->row('rows', 'id', 'row')['status']);
        self::assertFalse($database->isTransactionActive());
    }

    #[DataProvider('transactionEndings')]
    public function test_ending_an_in_memory_transaction_requires_an_active_one(string $finish): void
    {
        $database = new InMemoryRowDatabase();
        $this->expectException(\LogicException::class);
        $database->{$finish}();
    }
}
