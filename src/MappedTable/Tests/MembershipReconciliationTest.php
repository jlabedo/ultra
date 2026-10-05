<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class MembershipReconciliationTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_saved_membership_preserves_other_scopes_and_unspecified_columns_and_removes_late_rows(string $backend): void
    {
        $fixture = $this->fixture($backend, ['owner', 'id'], new MappedKey(['owner' => 'one']));
        $fixture->seed(['owner' => 'one', 'id' => 'keep', 'status' => 'old', 'note' => 'preserved']);
        $fixture->seed(['owner' => 'one', 'id' => 'remove']);
        $fixture->seed(['owner' => 'two', 'id' => 'keep', 'status' => 'outside']);
        $rows = $fixture->rows;
        $keep = new MappedKey(['owner' => 'one', 'id' => 'keep']);
        $added = new MappedKey(['owner' => 'one', 'id' => 'added']);
        $rows->reconcileRows([new MappedRow($keep, ['status' => 'updated']), new MappedRow($added, ['status' => 'new'])]);
        $fixture->seed(['owner' => 'one', 'id' => 'late']);
        self::assertSame(['status' => 'updated', 'note' => 'preserved'], $rows->row('keep', ['status', 'note']));
        self::assertFalse($rows->row('remove'));
        self::assertFalse($rows->row('late'));
        self::assertSame(['added', 'keep'], array_map(static fn ($row) => $row->key->value('id'), $rows->rows()));
        self::assertSame(['status' => 'old'], $fixture->stored($keep, ['status']));
        $fixture->flush();
        $rows->clear();
        self::assertCount(2, $rows->rows());
        self::assertSame(['status' => 'updated', 'note' => 'preserved'], $rows->row('keep', ['status', 'note']));
        self::assertSame(['status' => 'new'], $rows->row('added', ['status']));
        self::assertSame(['status' => 'outside'], $fixture->stored(new MappedKey(['owner' => 'two', 'id' => 'keep']), ['status']));

        $rows->reconcileRows([]);
        $fixture->flush();
        $rows->clear();
        self::assertSame([], $rows->rows());
        self::assertNotFalse($fixture->stored(new MappedKey(['owner' => 'two', 'id' => 'keep'])));
    }

    public function test_successive_memberships_supersede_writes_and_duplicate_keys_merge_in_input_order(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'existing', 'status' => 'old']);
        $rows = $fixture->rows;
        $rows->withKey('discarded')->expectAbsent();
        $rows->reconcileRows([new MappedRow(new MappedKey(['id' => 'discarded']), ['status' => 'new'])]);
        $rows->reconcileRows([
            new MappedRow(new MappedKey(['id' => 'existing']), ['status' => 'first', 'note' => 'preserved']),
            new MappedRow(new MappedKey(['id' => 'existing']), ['status' => 'last']),
        ]);
        self::assertFalse($rows->row('discarded'));
        self::assertSame(['status' => 'last', 'note' => 'preserved'], $rows->row('existing', ['status', 'note']));
        $fixture->flush();
        $rows->clear();
        self::assertFalse($fixture->stored('discarded'));
        self::assertSame(['status' => 'last', 'note' => 'preserved'], $fixture->stored('existing', ['status', 'note']));
    }

    #[DataProvider('backends')]
    public function test_an_empty_successor_cancels_an_unobserved_creation(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->rows->reconcileRows([new MappedRow(new MappedKey(['id' => 'added']), ['status' => 'new'])]);
        $fixture->rows->reconcileRows([]);
        $fixture->seed(['id' => 'late']);
        $fixture->flush();
        $fixture->rows->clear();
        self::assertSame([], $fixture->rows->rows());
    }

    public function test_explicit_operations_override_saved_membership_and_local_absence(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'removed', 'status' => 'old']);
        $rows = $fixture->rows;
        $rows->withKey('removed')->expectColumnValue('status', 'old');
        $rows->reconcileRows([]);
        $rows->withKey('removed')->expectAbsent();
        $rows->withKey('unseen')->expectAbsent();
        $fixture->seed(['id' => 'unseen', 'status' => 'late']);
        $rows->withKey('removed')->insert()->setColumn('status', 'restored');
        $rows->withKey('added')->insert()->setColumn('status', 'new');
        $rows->withKey('added')->delete();
        $rows->expectSameRowKeys(['removed']);
        $fixture->flush();
        $rows->clear();
        self::assertSame(['status' => 'restored'], $rows->row('removed', ['status']));
        self::assertFalse($rows->row('added'));
        self::assertFalse($rows->row('unseen'));
    }

    public function test_explicit_deletions_remove_keys_from_saved_membership(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'removed', 'status' => 'old']);
        $rows = $fixture->rows;
        $rows->reconcileRows([new MappedRow(new MappedKey(['id' => 'removed']), ['status' => 'updated'])]);
        $rows->withKey('removed')->delete();
        $rows->withKey('added')->upsert()->setColumn('status', 'new');
        $rows->expectSameRowKeys(['added']);
        $fixture->flush();
        $rows->clear();
        self::assertFalse($rows->row('removed'));
        self::assertSame(['status' => 'new'], $rows->row('added', ['status']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function guardedMemberships(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['membership', 'retained column', 'removed column'] as $expectation) {
                yield $backend.' '.$expectation => [$backend, $expectation];
            }
        }
    }

    #[DataProvider('guardedMemberships')]
    public function test_reconciliation_preserves_earlier_expectations_before_applying_writes(string $backend, string $expectation): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'existing', 'status' => 'old']);
        $rows = $fixture->rows;
        if ('membership' === $expectation) {
            $rows->expectSameRowKeys(['existing']);
        } else {
            $rows->withKey('existing')->expectColumnValue('status', 'old');
        }
        $desired = 'removed column' === $expectation ? [] : [new MappedRow(new MappedKey(['id' => 'existing']), ['status' => 'saved'])];
        $rows->reconcileRows($desired);
        $rows->expectSameRowKeys('removed column' === $expectation ? [] : ['existing']);
        if ('membership' === $expectation) {
            $fixture->seed(['id' => 'late']);
        } else {
            $fixture->seed(['id' => 'existing', 'status' => 'concurrent']);
        }
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            self::assertSame(['status' => 'membership' === $expectation ? 'old' : 'concurrent'], $fixture->stored('existing', ['status']));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function localConflicts(): iterable
    {
        yield 'different key set' => ['membership'];
        yield 'presence of a removed row' => ['presence'];
    }

    #[DataProvider('localConflicts')]
    public function test_expectations_must_agree_with_saved_local_membership(string $expectation): void
    {
        $rows = $this->fixture()->rows;
        $rows->reconcileRows([]);
        $this->expectException(ConcurrencyException::class);
        if ('membership' === $expectation) {
            $rows->expectSameRowKeys(['stale']);
        } else {
            $rows->withKey('removed')->expectPresent();
        }
    }
}
