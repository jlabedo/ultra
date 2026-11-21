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
        $rows->reconcileRows([
            new MappedRow($keep, ['status' => 'updated']),
            new MappedRow($added, ['status' => 'new']),
        ]);
        $fixture->seed(['owner' => 'one', 'id' => 'late']);
        static::assertSame(
            ['status' => 'updated', 'note' => 'preserved'],
            $rows->withKey('keep')->get(['status', 'note']),
        );
        static::assertNull($rows->withKey('remove')->get());
        static::assertNull($rows->withKey('late')->get());
        static::assertSame(['added', 'keep'], array_map(static fn($row) => $row->key->value('id'), $rows->rows()));
        static::assertSame(['status' => 'old'], $fixture->stored($keep, ['status']));
        $fixture->flush();
        $rows->clear();
        static::assertCount(2, $rows->rows());
        static::assertSame(
            ['status' => 'updated', 'note' => 'preserved'],
            $rows->withKey('keep')->get(['status', 'note']),
        );
        static::assertSame(['status' => 'new'], $rows->withKey('added')->get(['status']));
        static::assertSame(
            ['status' => 'outside'],
            $fixture->stored(new MappedKey(['owner' => 'two', 'id' => 'keep']), ['status']),
        );

        $rows->reconcileRows([]);
        $fixture->flush();
        $rows->clear();
        static::assertSame([], $rows->rows());
        static::assertNotFalse($fixture->stored(new MappedKey(['owner' => 'two', 'id' => 'keep'])));
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
        static::assertNull($rows->withKey('discarded')->get());
        static::assertSame(
            ['status' => 'last', 'note' => 'preserved'],
            $rows->withKey('existing')->get(['status', 'note']),
        );
        $fixture->flush();
        $rows->clear();
        static::assertFalse($fixture->stored('discarded'));
        static::assertSame(
            ['status' => 'last', 'note' => 'preserved'],
            $fixture->stored('existing', ['status', 'note']),
        );
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
        static::assertSame([], $fixture->rows->rows());
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
        static::assertSame(['status' => 'restored'], $rows->withKey('removed')->get(['status']));
        static::assertNull($rows->withKey('added')->get());
        static::assertNull($rows->withKey('unseen')->get());
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
        static::assertNull($rows->withKey('removed')->get());
        static::assertSame(['status' => 'new'], $rows->withKey('added')->get(['status']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function guardedMemberships(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['membership', 'retained column', 'removed column'] as $expectation) {
                yield $backend . ' ' . $expectation => [$backend, $expectation];
            }
        }
    }

    #[DataProvider('guardedMemberships')]
    public function test_reconciliation_preserves_earlier_expectations_before_applying_writes(
        string $backend,
        string $expectation,
    ): void {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'existing', 'status' => 'old']);
        $rows = $fixture->rows;
        if ('membership' === $expectation) {
            $rows->expectSameRowKeys(['existing']);
        } else {
            $rows->withKey('existing')->expectColumnValue('status', 'old');
        }
        $desired = 'removed column' === $expectation ? [] : [new MappedRow(new MappedKey(['id' => 'existing']), [
                'status' => 'saved',
            ])];
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
            static::assertSame(
                ['status' => 'membership' === $expectation ? 'old' : 'concurrent'],
                $fixture->stored('existing', ['status']),
            );
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

    #[DataProvider('backends')]
    public function test_reconciling_multiple_owners_keeps_empty_results_and_later_writes_distinct(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $one = new MappedKey(['tenant' => 'one', 'owner' => 'order']);
        $two = new MappedKey(['tenant' => 'two', 'owner' => 'order']);
        $oneLine = $one->mergedWith(new MappedKey(['id' => 'line']));
        $twoLine = $two->mergedWith(new MappedKey(['id' => 'line']));
        $fixture->seed([...$oneLine->values, 'status' => 'old']);
        $fixture->seed([...$twoLine->values, 'status' => 'old', 'note' => 'kept']);

        $rows->reconcileForOwner($two, []);
        $rows->reconcileForOwner($one, [new MappedRow($oneLine, ['status' => 'superseded'])]);
        $rows->reconcileForOwner($one, []);
        $rows->withKey($twoLine)->upsert()->setColumn('status', 'later');
        $fixture->seed([...$one->values, 'id' => 'late', 'status' => 'late']);

        $plan = $rows->updatePlan();
        static::assertSame([], $plan->ownerCollections[0]->retainedRowKeys);
        static::assertSame([$twoLine], $plan->ownerCollections[1]->retainedRowKeys);
        static::assertNull($plan->ownerCollections[0]->expectedRowKeys);
        static::assertSame([], $rows->rowsForOwner($one));
        $fixture->flush($rows);
        static::assertSame([], $rows->rowsForOwner($one));
        static::assertSame(['status' => 'later', 'note' => 'kept'], $fixture->stored($twoLine, ['status', 'note']));
    }

    public function test_an_invalid_owner_reconciliation_leaves_the_previous_overlay_intact(): void
    {
        [, $rows] = $this->ownedRows('memory');
        $owner = new MappedKey(['tenant' => 'one', 'owner' => 'order']);
        $line = $owner->mergedWith(new MappedKey(['id' => 'line']));
        $rows->reconcileForOwner($owner, [new MappedRow($line, ['status' => 'original'])]);

        try {
            $rows->reconcileForOwner($owner, [
                new MappedRow($line, ['status' => 'would overwrite']),
                new MappedRow(new MappedKey(['tenant' => 'other', 'owner' => 'order', 'id' => 'line']), []),
            ]);
            static::fail('A collection only accepts rows of its owner.');
        } catch (\InvalidArgumentException) {
            static::assertSame(['status' => 'original'], $rows->withKey($line)->get(['status']));
            static::assertCount(1, $rows->rows());
        }
    }

    #[DataProvider('backends')]
    public function test_owner_reconciliation_can_retain_an_unchanged_item_and_cancel_an_insert(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $owner = new MappedKey(['tenant' => 'one', 'owner' => 'order']);
        $kept = $owner->mergedWith(new MappedKey(['id' => 'kept']));
        $cancelled = $owner->mergedWith(new MappedKey(['id' => 'cancelled']));
        $fixture->seed([...$kept->values, 'status' => 'old']);

        $rows->withKey($kept)->expectColumnValue('status', 'old');
        $rows->withKey($cancelled)->expectAbsent();
        $rows->reconcileForOwner($owner, [new MappedRow($cancelled, ['status' => 'new'])]);
        $rows->withKey($cancelled)->delete();
        $rows->withKey($kept)->upsert()->setColumn('status', 'old');
        $rows->expectSameRowKeysForOwner($owner, [$kept]);

        static::assertSame(
            [$kept->values],
            array_map(static fn(MappedRow $row): array => $row->key->values, $rows->rowsForOwner($owner)),
        );
        $fixture->flush($rows);
        static::assertSame(['status' => 'old'], $fixture->stored($kept, ['status']));
        static::assertFalse($fixture->stored($cancelled, ['status']));
    }
}
