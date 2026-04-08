<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTable;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class MappedTableTest extends TestCase
{
    private const TABLE_NAME = 'users';
    private const ID_COLUMN = 'id';
    private const COLUMNS = ['status', 'note', 'version_a', 'version_b'];

    public function testFlushPassesSortedPlansToAdapter(): void
    {
        $adapter = new RecordingMappedTableAdapter();
        $table = new MappedTable($adapter, self::TABLE_NAME, self::ID_COLUMN);

        $table->withId('row-2')
            ->setColumn('status', 'second');

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'first');

        $table->flush();

        self::assertCount(1, $adapter->batches);
        self::assertSame(self::TABLE_NAME, $adapter->batches[0]['tableName']);
        self::assertSame(self::ID_COLUMN, $adapter->batches[0]['idColumn']);
        self::assertSame(
            ['row-1', 'row-2'],
            array_map(static fn (RowUpdatePlan $rowUpdate): string|int => $rowUpdate->id, $adapter->batches[0]['rowUpdates']),
        );
        self::assertSame(
            [RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_UPDATE],
            array_map(static fn (RowUpdatePlan $rowUpdate): string => $rowUpdate->mode, $adapter->batches[0]['rowUpdates']),
        );
    }

    public function testFlushUpdatesRowWhenExpectedColumnsMatch(): void
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
            ->setColumn('note', 'published');

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'active',
            'note' => 'published',
            'version_a' => 'A1',
            'version_b' => 'B1',
        ], $this->row($database, 'row-1'));
    }

    public function testFlushSupportsNullExpectedValues(): void
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
            ->setColumn('status', 'active');

        $this->flushInTransaction($database, $table);

        self::assertSame('active', $this->row($database, 'row-1')['status']);
    }

    public function testFlushThrowsWhenExpectedColumnsDoNotMatch(): void
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
            ->setColumn('note', 'next');

        $this->expectException(ConcurrencyException::class);

        try {
            $this->flushInTransaction($database, $table);
        } finally {
            self::assertSame('current', $this->row($database, 'row-1')['note']);
        }
    }

    public function testDeleteCancelsPendingInsertForTheSameRow(): void
    {
        [$database, $table] = $this->newTable();

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'pending');

        $table->withId('row-1')
            ->delete();

        $this->flushInTransaction($database, $table);

        self::assertFalse($this->row($database, 'row-1'));
    }

    public function testInsertAfterDeleteReplacesRowAndKeepsItsInitialExpectation(): void
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
            ->delete();

        $table->withId('row-1')
            ->insert()
            ->setColumn('status', 'recreated')
            ->setColumn('note', 'fresh')
            ->setColumn('version_a', 'A2');

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'recreated',
            'note' => 'fresh',
            'version_a' => 'A2',
            'version_b' => null,
        ], $this->row($database, 'row-1'));
    }

    public function testUpsertAfterDeleteReplacesRowAndKeepsItsInitialExpectation(): void
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
            ->delete();

        $table->withId('row-1')
            ->upsert()
            ->setColumn('status', 'upserted')
            ->setColumn('version_a', 'A2');

        $this->flushInTransaction($database, $table);

        self::assertSame([
            'id' => 'row-1',
            'status' => 'upserted',
            'note' => null,
            'version_a' => 'A2',
            'version_b' => null,
        ], $this->row($database, 'row-1'));
    }

    public function testDeleteRejectsSubsequentColumnUpdates(): void
    {
        [, $table] = $this->newTable();

        $this->expectException(ConcurrencyException::class);

        $table->withId('row-1')
            ->delete()
            ->setColumn('status', 'pending');
    }

    public function testInsertRejectsExpectedColumnValues(): void
    {
        [, $table] = $this->newTable();

        $this->expectException(ConcurrencyException::class);

        $table->withId('row-1')
            ->insert()
            ->expectColumnValue('version_a', 'A1');
    }

    public function testConflictingExpectationsForTheSameRowThrowImmediately(): void
    {
        [, $table] = $this->newTable();

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active');

        $this->expectException(ConcurrencyException::class);

        $table->withId('row-1')
            ->expectColumnValue('version_a', 'A2');
    }

    public function testClearDropsBufferedUpdates(): void
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
            ->setColumn('status', 'active');

        $table->clear();
        $this->flushInTransaction($database, $table);

        self::assertSame('pending', $this->row($database, 'row-1')['status']);
    }

    public function testExternalTransactionRollsBackInMemoryBatchOnFailure(): void
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
            ->setColumn('status', 'active');

        $table->withId('row-2')
            ->expectColumnValue('version_a', 'stale')
            ->setColumn('status', 'active');

        $this->expectException(ConcurrencyException::class);

        try {
            $this->flushInTransaction($database, $table);
        } finally {
            self::assertSame('pending', $this->row($database, 'row-1')['status']);
            self::assertSame('pending', $this->row($database, 'row-2')['status']);
        }
    }

    public function testFailedFlushWithoutExternalTransactionDoesNotRollBackEarlierWrites(): void
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
            ->setColumn('status', 'active');

        $table->withId('row-2')
            ->expectColumnValue('version_a', 'stale')
            ->setColumn('status', 'active');

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

    public function apply(string $tableName, string $idColumn, array $rowUpdates): void
    {
        $this->batches[] = [
            'tableName' => $tableName,
            'idColumn' => $idColumn,
            'rowUpdates' => $rowUpdates,
        ];
    }
}
