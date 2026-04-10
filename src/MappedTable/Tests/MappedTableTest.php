<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\BufferedRowUpdate;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTable;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PendingRowUpdate;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * @internal
 */
#[CoversClass(MappedTable::class)]
#[CoversClass(PendingRowUpdate::class)]
#[CoversClass(BufferedRowUpdate::class)]
#[CoversClass(RowUpdatePlan::class)]
#[CoversClass(InMemoryMappedTableAdapter::class)]
final class MappedTableTest extends TestCase
{
    private const TABLE_NAME = 'users';
    private const ID_COLUMN = 'id';
    private const COLUMNS = ['status', 'note', 'version_a', 'version_b'];

    public function test_flush_passes_sorted_plans_to_adapter(): void
    {
        $adapter = new RecordingMappedTableAdapter();
        $table = new MappedTable($adapter, self::TABLE_NAME, self::ID_COLUMN);

        $table->withId('row-2')
            ->setColumn('status', 'second')
        ;

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'first')
        ;

        $table->flush();

        self::assertCount(1, $adapter->batches);
        self::assertSame(self::TABLE_NAME, $adapter->batches[0]['tableName']);
        self::assertSame(self::ID_COLUMN, $adapter->batches[0]['idColumn']);
        self::assertSame(
            ['row-1', 'row-2'],
            array_map(static fn (RowUpdatePlan $rowUpdate): int|string => $rowUpdate->id, $adapter->batches[0]['rowUpdates']),
        );
        self::assertSame(
            [RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_UPDATE],
            array_map(static fn (RowUpdatePlan $rowUpdate): string => $rowUpdate->mode, $adapter->batches[0]['rowUpdates']),
        );
    }

    public function test_flush_updates_row_when_expected_columns_match(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->expectColumnValue('version_b', 'B1')
            ->setColumn('status', 'active')
            ->setColumn('note', 'published')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'active',
            'note' => 'published',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ], $this->row($database, 'row-1'));
    }

    public function test_flush_supports_null_expected_values(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => null,
            'version_a' => null,
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('note', null)
            ->expectColumnValue('version_a', null)
            ->setColumn('status', 'active')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame('active', $this->row($database, 'row-1')['status']);
    }

    public function test_row_reflects_buffered_updates_before_flush(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->setColumn('status', 'active')
            ->setColumn('note', 'published')
        ;

        self::assertSame([
            'id' => 'row-1',
            'status' => 'active',
            'note' => 'published',
            'version_a' => 'A1',
        ], $table->row('row-1', ['status', 'note', 'version_a']));
    }

    public function test_row_reflects_buffered_insert_and_delete_before_flush(): void
    {
        [, $table] = $this->newTable();

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'pending')
            ->setColumn('note', 'draft')
        ;

        self::assertSame([
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => null,
        ], $table->row('row-1', ['status', 'note', 'version_a']));

        $table->withId('row-1')
            ->delete()
        ;

        self::assertFalse($table->row('row-1', ['status']));
    }

    public function test_expect_absent_does_not_write_without_subsequent_changes(): void
    {
        [$database, $table] = $this->newTable();

        $table->withId('row-1')
            ->expectAbsent()
        ;

        $this->flushInTransaction($database, $table);

        self::assertFalse($this->row($database, 'row-1'));
    }

    public function test_expect_absent_turns_later_column_writes_into_insert(): void
    {
        [$database, $table] = $this->newTable();

        $table->withId('row-1')
            ->expectAbsent()
            ->setColumn('status', 'pending')
            ->setColumn('note', 'draft')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => null,
            'version_b' => null,
        ], $this->row($database, 'row-1'));
    }

    public function test_flush_throws_when_expected_columns_do_not_match(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'active',
            'note' => 'current',
            'version_a' => 'A2',
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('note', 'next')
        ;

        $this->expectException(ConcurrencyException::class);

        try {
            $this->flushInTransaction($database, $table);
        } finally {
            self::assertSame('current', $this->row($database, 'row-1')['note']);
        }
    }

    public function test_delete_cancels_pending_insert_for_the_same_row(): void
    {
        [$database, $table] = $this->newTable();

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'pending')
        ;

        $table->withId('row-1')
            ->delete()
        ;

        $this->flushInTransaction($database, $table);

        self::assertFalse($this->row($database, 'row-1'));
    }

    public function test_insert_after_delete_replaces_row_and_keeps_its_initial_expectation(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->delete()
        ;

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'recreated')
            ->setColumn('note', 'fresh')
            ->setColumn('version_a', 'A2')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'recreated',
            'note' => 'fresh',
            'version_a' => 'A2',
            'version_b' => null,
        ], $this->row($database, 'row-1'));
    }

    public function test_upsert_after_delete_replaces_row_and_keeps_its_initial_expectation(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->delete()
        ;

        $table->withId('row-1')
            ->upsert()
            ->setColumn('status', 'upserted')
            ->setColumn('version_a', 'A2')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'upserted',
            'note' => null,
            'version_a' => 'A2',
            'version_b' => null,
        ], $this->row($database, 'row-1'));
    }

    public function test_delete_rejects_subsequent_column_updates(): void
    {
        [, $table] = $this->newTable();

        $this->expectException(ConcurrencyException::class);

        $table->withId('row-1')
            ->delete()
            ->setColumn('status', 'pending')
        ;
    }

    public function test_insert_rejects_expected_column_values(): void
    {
        [, $table] = $this->newTable();

        $this->expectException(ConcurrencyException::class);

        $table->withId('row-1')
            ->insert()
            ->expectColumnValue('version_a', 'A1')
        ;
    }

    public function test_conflicting_expectations_for_the_same_row_throw_immediately(): void
    {
        [, $table] = $this->newTable();

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $this->expectException(ConcurrencyException::class);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A2')
        ;
    }

    public function test_clear_drops_buffered_updates(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $table->clear();
        $this->flushInTransaction($database, $table);

        self::assertSame('pending', $this->row($database, 'row-1')['status']);
    }

    public function test_external_transaction_rolls_back_in_memory_batch_on_failure(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);
        $this->seedRow($database, [
            'id' => 'row-2',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A2',
            'version_b' => 'B2',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $table->withId('row-2')
            ->expectColumnValue('version_a', 'stale')
            ->setColumn('status', 'active')
        ;

        $this->expectException(ConcurrencyException::class);

        try {
            $this->flushInTransaction($database, $table);
        } finally {
            self::assertSame('pending', $this->row($database, 'row-1')['status']);
            self::assertSame('pending', $this->row($database, 'row-2')['status']);
        }
    }

    public function test_failed_flush_without_external_transaction_does_not_roll_back_earlier_writes(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ]);
        $this->seedRow($database, [
            'id' => 'row-2',
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => 'A2',
            'version_b' => 'B2',
        ]);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $table->withId('row-2')
            ->expectColumnValue('version_a', 'stale')
            ->setColumn('status', 'active')
        ;

        $this->expectException(ConcurrencyException::class);

        try {
            $table->flush();
        } finally {
            self::assertSame('active', $this->row($database, 'row-1')['status']);
            self::assertSame('pending', $this->row($database, 'row-2')['status']);
        }
    }

    /**
     * @return array{InMemoryRowDatabase, MappedTable}
     */
    private function newTable(): array
    {
        $database = new InMemoryRowDatabase();
        $adapter = new InMemoryMappedTableAdapter($database);

        return [$database, new MappedTable($adapter, self::TABLE_NAME, self::ID_COLUMN)];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function seedRow(InMemoryRowDatabase $database, array $row): void
    {
        $database->seedRow(self::TABLE_NAME, self::ID_COLUMN, $row);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function row(InMemoryRowDatabase $database, string $id): array|false
    {
        return $database->row(self::TABLE_NAME, self::ID_COLUMN, $id, self::COLUMNS);
    }

    private function flushInTransaction(InMemoryRowDatabase $database, MappedTable $table): void
    {
        $database->beginTransaction();

        try {
            $table->flush();
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->isTransactionActive()) {
                $database->rollBack();
            }

            throw $exception;
        }
    }
}

final class RecordingMappedTableAdapter implements MappedTableAdapter
{
    /** @var list<array{tableName: string, idColumn: string, rowUpdates: list<RowUpdatePlan>}> */
    public array $batches = [];

    public function row(string $tableName, string $idColumn, int|string $id, array $columns = []): array|false
    {
        return false;
    }

    public function apply(string $tableName, string $idColumn, array $rowUpdates): void
    {
        $this->batches[] = [
            'tableName' => $tableName,
            'idColumn' => $idColumn,
            'rowUpdates' => $rowUpdates,
        ];
    }
}
