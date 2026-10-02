<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\BufferedRowUpdate;
use Ultra\MappedTable\InMemoryMappedRowSetAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\PendingRowUpdate;
use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * @internal
 */
#[CoversClass(MappedRowSet::class)]
#[CoversClass(PendingRowUpdate::class)]
#[CoversClass(BufferedRowUpdate::class)]
#[CoversClass(RowSetUpdatePlan::class)]
#[CoversClass(RowUpdatePlan::class)]
#[CoversClass(InMemoryMappedRowSetAdapter::class)]
final class MappedRowSetTest extends TestCase
{
    private const TABLE_NAME = 'users';
    private const ID_COLUMN = 'id';
    private const COLUMNS = ['status', 'note', 'version_a', 'version_b'];

    public function test_reconciliation_upserts_desired_rows_and_deletes_removed_rows_within_scope(): void
    {
        $database = new InMemoryRowDatabase();
        foreach ([['owner' => 'one', 'id' => 'keep', 'status' => 'old', 'note' => 'preserved'], ['owner' => 'one', 'id' => 'remove'], ['owner' => 'two', 'id' => 'other']] as $row) {
            $database->seedRow('items', 'id', $row);
        }
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter($database, 'items', ['owner', 'id'], new MappedKey(['owner' => 'one'])));
        $keep = new MappedKey(['owner' => 'one', 'id' => 'keep']);
        $added = new MappedKey(['owner' => 'one', 'id' => 'added']);

        $table->reconcileRows([new MappedRow($keep, ['status' => 'updated']), new MappedRow($added, ['status' => 'new'])]);

        self::assertSame(['status' => 'updated', 'note' => 'preserved'], $table->row($keep, ['status', 'note']));
        self::assertFalse($table->row('remove'));
        self::assertSame(['status' => 'old', 'note' => 'preserved'], $database->rowByKey('items', $keep, ['status', 'note']));
        $this->flushInTransaction($database, $table);
        $table->clear();
        self::assertCount(2, $table->rows());
        self::assertSame(['status' => 'updated', 'note' => 'preserved'], $table->row($keep, ['status', 'note']));
        self::assertSame(['status' => 'new'], $table->row($added, ['status']));
        self::assertNotFalse($database->rowByKey('items', new MappedKey(['owner' => 'two', 'id' => 'other'])));
    }

    public function test_successive_reconciliations_include_buffered_rows_and_an_empty_set_removes_them(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'existing', 'status' => 'old']);
        $existing = new MappedKey(['id' => 'existing']);
        $added = new MappedKey(['id' => 'added']);
        $table->withKey($added)->expectAbsent();

        $table->reconcileRows([new MappedRow($added, ['status' => 'new'])]);
        $table->reconcileRows([new MappedRow($existing, ['status' => 'restored'])]);
        self::assertSame(['status' => 'restored'], $table->row($existing, ['status']));
        self::assertFalse($table->row($added, ['status']));
        $table->reconcileRows([]);
        self::assertSame([], $table->rows());
        $this->flushInTransaction($database, $table);
        $table->clear();
        self::assertSame([], $table->rows());
    }

    public function test_reconciliation_keeps_membership_expectations(): void
    {
        [$database, $table] = $this->newTable();
        $key = new MappedKey(['id' => 'existing']);
        $this->seedRow($database, ['id' => 'existing', 'status' => 'old']);
        $table->expectSameRowKeys([$key]);
        $this->seedRow($database, ['id' => 'concurrent', 'status' => 'new']);
        $table->reconcileRows([new MappedRow($key, ['status' => 'updated'])]);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_reconciliation_merges_duplicate_keys_in_input_order(): void
    {
        [$database, $table] = $this->newTable();
        $key = new MappedKey(['id' => 'existing']);
        $table->reconcileRows([
            new MappedRow($key, ['status' => 'first', 'note' => 'preserved']),
            new MappedRow($key, ['status' => 'last']),
        ]);
        $this->flushInTransaction($database, $table);
        $table->clear();

        self::assertSame(['status' => 'last', 'note' => 'preserved'], $table->row($key, ['status', 'note']));
    }

    public function test_reconciliation_keeps_column_expectations(): void
    {
        [$database, $table] = $this->newTable();
        $key = new MappedKey(['id' => 'existing']);
        $this->seedRow($database, ['id' => 'existing', 'status' => 'old']);
        $table->withKey($key)->expectColumnValue('status', 'old');
        $table->reconcileRows([new MappedRow($key, ['status' => 'updated'])]);
        $this->seedRow($database, ['id' => 'existing', 'status' => 'concurrent']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_composite_key_addresses_and_returns_one_structured_row(): void
    {
        $database = new InMemoryRowDatabase();
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter(
            $database,
            'acceptances',
            ['identity_id', 'terms_id'],
        ));
        $key = new MappedKey(['identity_id' => 'identity-1', 'terms_id' => 'terms-2026']);

        $table->withKey($key)->insert()->setColumn('accepted_at', 'now');
        $this->flushInTransaction($database, $table);

        self::assertSame(['accepted_at' => 'now'], $table->row($key, ['accepted_at']));
        $rows = $table->rows(['accepted_at']);
        self::assertCount(1, $rows);
        self::assertSame($key->values, $rows[0]->key->values);
        self::assertSame(['accepted_at' => 'now'], $rows[0]->columns);
    }

    public function test_composite_key_rejects_scalar_shortcut(): void
    {
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter(
            new InMemoryRowDatabase(),
            'acceptances',
            ['identity_id', 'terms_id'],
        ));

        $this->expectException(\InvalidArgumentException::class);
        $table->row('identity-1');
    }

    public function test_flush_passes_sorted_plans_to_adapter(): void
    {
        $adapter = new RecordingMappedRowSetAdapter();
        $table = new MappedRowSet($adapter);

        $table->withKey('row-2')
            ->setColumn('status', 'second')
        ;

        $table->withKey('row-1')
            ->insert()
            ->setColumn('status', 'first')
        ;

        $table->flush();

        self::assertCount(1, $adapter->batches);
        self::assertSame(
            ['row-1', 'row-2'],
            array_map(static fn (RowUpdatePlan $rowUpdate): int|string => $rowUpdate->key->value('id'), $adapter->batches[0]['rowUpdates']),
        );
        self::assertSame(
            [RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_UPDATE],
            array_map(static fn (RowUpdatePlan $rowUpdate): string => $rowUpdate->mode, $adapter->batches[0]['rowUpdates']),
        );
    }

    public function test_presence_expectation_accepts_a_buffered_creation_but_rejects_a_buffered_deletion(): void
    {
        $database = new InMemoryRowDatabase();
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter($database, 'users'));
        $table->withKey('user-1')->insert()->setColumn('status', 'pending');
        $table->withKey('user-1')->expectPresent();
        $this->flushInTransaction($database, $table);
        $table->clear();
        self::assertSame(['status' => 'pending'], $table->row('user-1', ['status']));
        $table->withKey('user-1')->delete();
        $this->expectException(ConcurrencyException::class);
        $table->withKey('user-1')->expectPresent();
    }

    public function test_presence_expectation_rejects_a_conflicting_absence_expectation(): void
    {
        $table = new MappedRowSet(new InMemoryMappedRowSetAdapter(new InMemoryRowDatabase(), 'users'));
        $table->withKey('user-1')->expectAbsent();
        $this->expectException(ConcurrencyException::class);
        $table->withKey('user-1')->expectPresent();
    }

    public function test_it_has_pending_changes_for_writes(): void
    {
        $table = new MappedRowSet(new RecordingMappedRowSetAdapter());

        self::assertFalse($table->hasPendingChanges());

        $table->withKey('row-1')->setColumn('status', 'active');

        self::assertTrue($table->hasPendingChanges());
    }

    public function test_row_expectations_without_writes_are_pending_checks(): void
    {
        $adapter = new RecordingMappedRowSetAdapter();
        $table = new MappedRowSet($adapter);

        $table->withKey('row-1')->expectColumnValue('version', 1);
        $table->withKey('row-2')->expectAbsent();

        self::assertTrue($table->hasPendingChanges());
        $table->flush();
        self::assertSame(
            [
                ['row-1', RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::EXPECTED_PRESENT, ['version' => 1]],
                ['row-2', RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::EXPECTED_ABSENT, []],
            ],
            array_map(
                static fn (RowUpdatePlan $plan): array => [$plan->key->value('id'), $plan->mode, $plan->expectedPresence, $plan->expectedColumns],
                $adapter->batches[0]['rowUpdates'],
            ),
        );
    }

    public function test_a_row_expectation_without_write_is_checked_at_flush(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending', 'note' => 'draft']);

        $table->withKey('row-1')->expectColumnValues(['status' => 'pending', 'note' => 'draft']);
        $this->flushInTransaction($database, $table);
        $table->clear();

        $table->withKey('row-1')->expectColumnValue('status', 'pending');
        $database->writeRow(self::TABLE_NAME, self::ID_COLUMN, ['id' => 'row-1', 'status' => 'paid', 'note' => 'draft']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_an_absent_expectation_without_write_is_checked_at_flush(): void
    {
        [$database, $table] = $this->newTable();

        $table->withKey('row-1')->expectAbsent();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_an_expected_row_is_read_without_its_check_plan(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending']);

        $table->withKey('row-1')->expectColumnValue('status', 'pending');
        $table->withKey('row-2')->expectAbsent();

        self::assertSame(['status' => 'pending'], $table->row('row-1', ['status']));
        self::assertFalse($table->row('row-2'));
        self::assertSame(['row-1'], array_map(static fn (MappedRow $row): int|string => $row->key->value('id'), $table->rows()));
        $table->expectSameRowKeys(['row-1']);
    }

    public function test_an_update_writing_only_expected_values_becomes_a_check(): void
    {
        $adapter = new RecordingMappedRowSetAdapter();
        $table = new MappedRowSet($adapter);

        $table->withKey('row-1')
            ->expectColumnValues(['status' => 'pending', 'note' => 'draft'])
            ->setColumns(['status' => 'pending', 'note' => 'draft'])
        ;
        $table->withKey('row-2')
            ->expectColumnValues(['status' => 'pending', 'note' => 'draft'])
            ->setColumns(['status' => 'paid', 'note' => 'draft'])
        ;
        $table->flush();

        $plans = $adapter->batches[0]['rowUpdates'];
        self::assertSame([RowUpdatePlan::MODE_EXPECT, RowUpdatePlan::MODE_UPDATE], array_map(static fn (RowUpdatePlan $plan): string => $plan->mode, $plans));
        self::assertSame([], $plans[0]->setColumns);
        self::assertSame(['status' => 'pending', 'note' => 'draft'], $plans[0]->expectedColumns);
    }

    public function test_an_unchanged_row_still_detects_a_concurrent_change(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending']);

        $table->withKey('row-1')->expectColumnValue('status', 'pending')->setColumn('status', 'pending');
        $database->writeRow(self::TABLE_NAME, self::ID_COLUMN, ['id' => 'row-1', 'status' => 'paid']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_row_set_expectations_are_pending_changes(): void
    {
        $table = new MappedRowSet(new RecordingMappedRowSetAdapter());

        $table->expectSameRowKeys(['row-1']);

        self::assertTrue($table->hasPendingChanges());
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

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->expectColumnValue('version_b', 'B1')
            ->setColumn('status', 'active')
            ->setColumn('note', 'published')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
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

        $table->withKey('row-1')
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

        $table->withKey('row-1')
            ->setColumn('status', 'active')
            ->setColumn('note', 'published')
        ;

        self::assertSame([
            'status' => 'active',
            'note' => 'published',
            'version_a' => 'A1',
        ], $table->row('row-1', ['status', 'note', 'version_a']));
    }

    public function test_row_reflects_buffered_insert_and_delete_before_flush(): void
    {
        [, $table] = $this->newTable();

        $table->withKey('row-1')
            ->insert()
            ->setColumn('status', 'pending')
            ->setColumn('note', 'draft')
        ;

        self::assertSame([
            'status' => 'pending',
            'note' => 'draft',
            'version_a' => null,
        ], $table->row('row-1', ['status', 'note', 'version_a']));

        $table->withKey('row-1')
            ->delete()
        ;

        self::assertFalse($table->row('row-1', ['status']));
    }

    public function test_rows_returns_all_rows_in_deterministic_order(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-2',
            'status' => 'active',
            'note' => 'published',
        ]);
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $rows = $table->rows(['status', 'note']);
        self::assertSame(['row-1', 'row-2'], array_map(static fn ($row): int|string => $row->key->value('id'), $rows));
        self::assertSame([
            [
                'status' => 'pending',
                'note' => 'draft',
            ],
            [
                'status' => 'active',
                'note' => 'published',
            ],
        ], array_map(static fn ($row): array => $row->columns, $rows));
    }

    public function test_rows_reflects_buffered_changes_before_flush(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, [
            'id' => 'row-1',
            'status' => 'pending',
            'note' => 'draft',
        ]);
        $this->seedRow($database, [
            'id' => 'row-2',
            'status' => 'pending',
            'note' => 'draft',
        ]);

        $table->withKey('row-1')
            ->setColumn('status', 'active')
        ;
        $table->withKey('row-2')
            ->delete()
        ;
        $table->withKey('row-3')
            ->insert()
            ->setColumn('status', 'new')
            ->setColumn('note', 'fresh')
        ;

        $rows = $table->rows(['status', 'note']);
        self::assertSame(['row-1', 'row-3'], array_map(static fn ($row): int|string => $row->key->value('id'), $rows));
        self::assertSame([
            [
                'status' => 'active',
                'note' => 'draft',
            ],
            [
                'status' => 'new',
                'note' => 'fresh',
            ],
        ], array_map(static fn ($row): array => $row->columns, $rows));
    }

    public function test_expect_absent_does_not_write_without_subsequent_changes(): void
    {
        [$database, $table] = $this->newTable();

        $table->withKey('row-1')
            ->expectAbsent()
        ;

        $this->flushInTransaction($database, $table);

        self::assertFalse($this->row($database, 'row-1'));
    }

    public function test_expect_same_row_ids_succeeds_when_ids_match(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-2']);
        $this->seedRow($database, ['id' => 'row-1']);

        $table->expectSameRowKeys(['row-1', 'row-2']);
        $this->flushInTransaction($database, $table);

        self::assertSame(['row-1', 'row-2'], array_keys($database->rows(self::TABLE_NAME, 'id')));
    }

    public function test_expect_same_row_ids_throws_when_row_was_inserted(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1']);

        $table->expectSameRowKeys(['row-1']);
        $this->seedRow($database, ['id' => 'row-2']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_expect_same_row_ids_throws_when_row_was_deleted(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1']);
        $this->seedRow($database, ['id' => 'row-2']);

        $table->expectSameRowKeys(['row-1', 'row-2']);
        $database->deleteRow(self::TABLE_NAME, self::ID_COLUMN, 'row-2');

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_expect_same_row_ids_rejects_duplicate_ids(): void
    {
        [, $table] = $this->newTable();

        $this->expectException(\InvalidArgumentException::class);
        $table->expectSameRowKeys(['row-1', 'row-1']);
    }

    public function test_conflicting_row_set_expectations_throw_immediately(): void
    {
        [, $table] = $this->newTable();

        $table->expectSameRowKeys(['row-1', 'row-2']);

        $this->expectException(ConcurrencyException::class);
        $table->expectSameRowKeys(['row-1']);
    }

    public function test_expect_absent_turns_later_column_writes_into_insert(): void
    {
        [$database, $table] = $this->newTable();

        $table->withKey('row-1')
            ->expectAbsent()
            ->setColumn('status', 'pending')
            ->setColumn('note', 'draft')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
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

        $table->withKey('row-1')
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

        $table->withKey('row-1')
            ->insert()
            ->setColumn('status', 'pending')
        ;

        $table->withKey('row-1')
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

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->delete()
        ;

        $table->withKey('row-1')
            ->insert()
            ->setColumn('status', 'recreated')
            ->setColumn('note', 'fresh')
            ->setColumn('version_a', 'A2')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
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

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->delete()
        ;

        $table->withKey('row-1')
            ->upsert()
            ->setColumn('status', 'upserted')
            ->setColumn('version_a', 'A2')
        ;

        $this->flushInTransaction($database, $table);

        self::assertSame([
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

        $table->withKey('row-1')
            ->delete()
            ->setColumn('status', 'pending')
        ;
    }

    public function test_insert_rejects_expected_column_values(): void
    {
        [, $table] = $this->newTable();

        $this->expectException(ConcurrencyException::class);

        $table->withKey('row-1')
            ->insert()
            ->expectColumnValue('version_a', 'A1')
        ;
    }

    public function test_conflicting_expectations_for_the_same_row_throw_immediately(): void
    {
        [, $table] = $this->newTable();

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $this->expectException(ConcurrencyException::class);

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A2')
        ;
    }

    public function test_expectation_on_a_column_written_in_the_unit_of_work_is_checked_against_the_pending_value(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending']);

        $table->withKey('row-1')->expectColumnValue('status', 'pending')->setColumn('status', 'paid');
        $table->withKey('row-1')->expectColumnValue('status', 'paid')->setColumn('status', 'shipped');
        $this->flushInTransaction($database, $table);

        self::assertSame('shipped', $this->row($database, 'row-1')['status']);
    }

    public function test_stale_expectation_on_a_column_written_in_the_unit_of_work_fails_immediately(): void
    {
        [, $table] = $this->newTable();
        $table->withKey('row-1')->expectColumnValue('status', 'pending')->setColumn('status', 'paid');

        $this->expectException(ConcurrencyException::class);
        $table->withKey('row-1')->expectColumnValue('status', 'pending');
    }

    public function test_expectation_on_a_column_not_written_in_the_unit_of_work_still_guards_committed_data(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending', 'note' => 'draft']);

        $table->withKey('row-1')->setColumn('status', 'paid');
        $table->withKey('row-1')->expectColumnValue('status', 'paid')->expectColumnValue('note', 'draft');
        $database->writeRow(self::TABLE_NAME, self::ID_COLUMN, ['id' => 'row-1', 'status' => 'pending', 'note' => 'changed']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_expectation_on_a_row_inserted_in_the_unit_of_work_is_checked_against_the_insert(): void
    {
        [$database, $table] = $this->newTable();

        $table->withKey('row-1')->expectAbsent()->setColumn('status', 'pending');
        $table->withKey('row-1')->expectColumnValue('status', 'pending')->expectColumnValue('note', null)->setColumn('note', 'draft');
        $this->flushInTransaction($database, $table);

        self::assertSame(['status' => 'pending', 'note' => 'draft', 'version_a' => null, 'version_b' => null], $this->row($database, 'row-1'));
    }

    public function test_expecting_a_row_deleted_in_the_unit_of_work_fails_immediately(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending']);
        $table->withKey('row-1')->delete();

        $this->expectException(ConcurrencyException::class);
        $table->withKey('row-1')->expectColumnValue('status', 'pending');
    }

    public function test_expect_absent_is_satisfied_by_a_delete_in_the_unit_of_work(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1', 'status' => 'pending']);

        $table->withKey('row-1')->expectColumnValue('status', 'pending')->delete();
        $table->withKey('row-1')->expectAbsent();
        $this->flushInTransaction($database, $table);

        self::assertFalse($this->row($database, 'row-1'));
    }

    public function test_expect_absent_fails_immediately_on_a_row_written_in_the_unit_of_work(): void
    {
        [, $table] = $this->newTable();
        $table->withKey('row-1')->upsert()->setColumn('status', 'pending');

        $this->expectException(ConcurrencyException::class);
        $table->withKey('row-1')->expectAbsent();
    }

    public function test_row_set_expectation_can_include_rows_written_in_the_unit_of_work(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1']);

        $table->expectSameRowKeys(['row-1']);
        $table->withKey('row-2')->upsert()->setColumn('status', 'new');
        $table->expectSameRowKeys(['row-1', 'row-2']);
        $this->flushInTransaction($database, $table);

        self::assertSame(['row-1', 'row-2'], array_keys($database->rows(self::TABLE_NAME, 'id')));
    }

    public function test_row_set_expectation_registered_after_writes_still_guards_other_rows(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1']);

        $table->withKey('row-2')->upsert()->setColumn('status', 'new');
        $table->expectSameRowKeys(['row-1', 'row-2']);
        $this->seedRow($database, ['id' => 'row-3']);

        $this->expectException(ConcurrencyException::class);
        $this->flushInTransaction($database, $table);
    }

    public function test_row_set_expectation_disagreeing_with_the_unit_of_work_fails_immediately(): void
    {
        [$database, $table] = $this->newTable();
        $this->seedRow($database, ['id' => 'row-1']);
        $this->seedRow($database, ['id' => 'row-2']);

        $table->expectSameRowKeys(['row-1', 'row-2']);
        $table->withKey('row-3')->upsert()->setColumn('status', 'new');

        $this->expectException(ConcurrencyException::class);
        $table->expectSameRowKeys(['row-2', 'row-3']);
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

        $table->withKey('row-1')
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

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $table->withKey('row-2')
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

        $table->withKey('row-1')
            ->expectColumnValue('version_a', 'A1')
            ->setColumn('status', 'active')
        ;

        $table->withKey('row-2')
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
     * @return array{InMemoryRowDatabase, MappedRowSet}
     */
    private function newTable(): array
    {
        $database = new InMemoryRowDatabase();
        $adapter = new InMemoryMappedRowSetAdapter($database, self::TABLE_NAME, [self::ID_COLUMN]);

        return [$database, new MappedRowSet($adapter)];
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

    private function flushInTransaction(InMemoryRowDatabase $database, MappedRowSet $table): void
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

final class RecordingMappedRowSetAdapter implements MappedRowSetAdapter
{
    /** @var list<array{expectedRowKeys: list<MappedKey>|null, rowUpdates: list<RowUpdatePlan>}> */
    public array $batches = [];

    public function keyColumns(): array
    {
        return ['id'];
    }

    public function scope(): ?MappedKey
    {
        return null;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        return false;
    }

    public function rows(array $columns = []): array
    {
        return [];
    }

    public function apply(RowSetUpdatePlan $plan): void
    {
        $this->batches[] = [
            'expectedRowKeys' => $plan->expectedRowKeys,
            'rowUpdates' => $plan->rowUpdates,
        ];
    }
}
