<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\MappedTable\InMemoryRowDatabase;

/**
 * @internal
 */
#[CoversClass(InMemoryRowDatabase::class)]
final class InMemoryRowDatabaseTest extends TestCase
{
    public function test_commit_keeps_changes_applied_during_transaction(): void
    {
        $database = new InMemoryRowDatabase();
        $database->seedRow('users', 'id', [
            'id' => 'row-1',
            'status' => 'pending',
        ]);

        $database->beginTransaction();
        self::assertTrue($database->isTransactionActive());

        $row = $database->row('users', 'id', 'row-1');
        self::assertIsArray($row);

        $row['status'] = 'active';
        $database->writeRow('users', 'id', $row);
        $database->commit();

        self::assertSame([
            'id' => 'row-1',
            'status' => 'active',
        ], $database->row('users', 'id', 'row-1'));
        self::assertFalse($database->isTransactionActive());
    }

    public function test_rollback_restores_snapshot_taken_at_begin_transaction(): void
    {
        $database = new InMemoryRowDatabase();
        $database->seedRow('users', 'id', [
            'id' => 'row-1',
            'status' => 'pending',
        ]);

        $database->beginTransaction();
        self::assertTrue($database->isTransactionActive());

        $row = $database->row('users', 'id', 'row-1');
        self::assertIsArray($row);

        $row['status'] = 'active';
        $database->writeRow('users', 'id', $row);
        $database->rollBack();

        self::assertSame([
            'id' => 'row-1',
            'status' => 'pending',
        ], $database->row('users', 'id', 'row-1'));
        self::assertFalse($database->isTransactionActive());
    }

    public function test_rollback_without_active_transaction_throws(): void
    {
        $database = new InMemoryRowDatabase();

        $this->expectException(\LogicException::class);

        $database->rollBack();
    }

    public function test_commit_without_active_transaction_throws(): void
    {
        $database = new InMemoryRowDatabase();

        $this->expectException(\LogicException::class);

        $database->commit();
    }

    // --- scoped row semantics -------------------------------------------------
    //
    // Scopes partition a table into independent windows keyed by one or more
    // columns. Operations under one scope must not see or affect rows under
    // another scope, even when ids collide.

    public function test_same_id_under_different_scopes_coexists(): void
    {
        $database = new InMemoryRowDatabase();

        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'name' => 'alice'], ['tenant' => 'A']);
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'B', 'name' => 'bob'], ['tenant' => 'B']);

        self::assertSame(
            ['id' => 'row-1', 'tenant' => 'A', 'name' => 'alice'],
            $database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'A']),
        );
        self::assertSame(
            ['id' => 'row-1', 'tenant' => 'B', 'name' => 'bob'],
            $database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'B']),
        );
    }

    public function test_writerow_under_one_scope_does_not_touch_other_scopes(): void
    {
        $database = new InMemoryRowDatabase();

        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'name' => 'alice'], ['tenant' => 'A']);
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'B', 'name' => 'bob'], ['tenant' => 'B']);

        // Re-upsert scope A — scope B row must survive byte-identical.
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'name' => 'alice-v2'], ['tenant' => 'A']);

        self::assertSame('alice-v2', $database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'A'])['name']);
        self::assertSame('bob', $database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'B'])['name']);
    }

    public function test_deleterow_under_one_scope_does_not_touch_other_scopes(): void
    {
        $database = new InMemoryRowDatabase();

        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A'], ['tenant' => 'A']);
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'B'], ['tenant' => 'B']);

        $database->deleteRow('users', 'id', 'row-1', ['tenant' => 'A']);

        self::assertFalse($database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'A']));
        self::assertNotFalse($database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'B']));
    }

    public function test_row_returns_false_for_wrong_scope(): void
    {
        $database = new InMemoryRowDatabase();
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A'], ['tenant' => 'A']);

        self::assertNotFalse($database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'A']));
        self::assertFalse($database->row('users', 'id', 'row-1', scopeColumns: ['tenant' => 'B']));
    }

    public function test_rows_filters_by_scope(): void
    {
        $database = new InMemoryRowDatabase();
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'name' => 'alice'], ['tenant' => 'A']);
        $database->writeRow('users', 'id', ['id' => 'row-2', 'tenant' => 'A', 'name' => 'amy'], ['tenant' => 'A']);
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'B', 'name' => 'bob'], ['tenant' => 'B']);

        $aRows = $database->rows('users', 'id', scopeColumns: ['tenant' => 'A']);
        $bRows = $database->rows('users', 'id', scopeColumns: ['tenant' => 'B']);

        self::assertSame(['row-1', 'row-2'], array_keys($aRows));
        self::assertSame('alice', $aRows['row-1']['name']);
        self::assertSame(['row-1'], array_keys($bRows));
        self::assertSame('bob', $bRows['row-1']['name']);
    }

    public function test_multi_column_scope_requires_all_columns_to_match(): void
    {
        $database = new InMemoryRowDatabase();
        $scopeAA = ['tenant' => 'A', 'env' => 'prod'];
        $scopeAB = ['tenant' => 'A', 'env' => 'dev'];

        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'env' => 'prod'], $scopeAA);
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'env' => 'dev'], $scopeAB);

        self::assertNotFalse($database->row('users', 'id', 'row-1', scopeColumns: $scopeAA));
        self::assertNotFalse($database->row('users', 'id', 'row-1', scopeColumns: $scopeAB));

        // Re-upsert one scope, the other survives.
        $database->writeRow('users', 'id', ['id' => 'row-1', 'tenant' => 'A', 'env' => 'prod', 'flag' => 1], $scopeAA);
        self::assertSame(1, $database->row('users', 'id', 'row-1', scopeColumns: $scopeAA)['flag']);
        self::assertArrayNotHasKey('flag', $database->row('users', 'id', 'row-1', scopeColumns: $scopeAB));
    }

    public function test_unscoped_writerow_upserts_by_id_alone(): void
    {
        $database = new InMemoryRowDatabase();
        $database->writeRow('users', 'id', ['id' => 'row-1', 'name' => 'v1']);
        $database->writeRow('users', 'id', ['id' => 'row-1', 'name' => 'v2']);

        self::assertSame('v2', $database->row('users', 'id', 'row-1')['name']);
        self::assertCount(1, $database->rows('users', 'id'));
    }

    // --- owned-set + row-set coexistence on the same backing table -----------
    //
    // After the storage unification, both views are predicates over one list.
    // Pin the cross-view invariants: an owned-set write isn't shadowed by a
    // row-set view that ignores parent columns, and vice versa.

    public function test_owned_set_writes_dont_corrupt_row_set_reads_on_unrelated_table(): void
    {
        $database = new InMemoryRowDatabase();
        $database->writeRow('users', 'id', ['id' => 'u-1', 'name' => 'alice']);
        $database->replaceOwnedSet('intervals', ['subject_id' => 'u-1', 'capability_id' => 'c-1'], [
            ['eligible_from' => '2025-01-01', 'eligible_to' => null],
        ]);

        // Each view sees only its own table.
        self::assertSame([['id' => 'u-1', 'name' => 'alice']], array_values($database->rows('users', 'id')));
        self::assertCount(1, $database->rowsForParent('intervals', ['subject_id' => 'u-1', 'capability_id' => 'c-1']));
        self::assertSame([], $database->rowsForParent('users', ['subject_id' => 'u-1']));
    }
}
