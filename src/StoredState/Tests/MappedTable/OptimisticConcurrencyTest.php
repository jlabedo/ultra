<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class OptimisticConcurrencyTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, bool, string}> */
    public static function unsupportedAbsenceChecks(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach ([false, true] as $scoped) {
                foreach (['expectAbsent', 'delete', 'cancel'] as $operation) {
                    yield $backend . ' ' . ($scoped ? 'scoped' : 'unscoped') . ' ' . $operation => [
                        $backend,
                        $scoped,
                        $operation,
                    ];
                }
            }
        }
    }

    #[DataProvider('unsupportedAbsenceChecks')]
    public function test_table_policy_rejects_final_absence_observations_before_writing(
        string $backend,
        bool $scoped,
        string $operation,
    ): void {
        $fixture = $this->fixture(
            $backend,
            $scoped ? ['tenant', 'id'] : ['id'],
            $scoped ? new MappedKey(['tenant' => 'tenant-1']) : null,
            allowAbsentObservations: false,
        );
        $fixture->rows->withKey('first')->insert()->setColumn('status', 'new');
        $row = $fixture->rows->withKey('absent')->expectAbsent();
        if ('cancel' === $operation) {
            $row->insert()->setColumn('status', 'cancelled');
        }
        if ('expectAbsent' !== $operation) {
            $row->delete();
        }

        static::assertCount(2, $fixture->rows->updatePlan()->rowUpdates);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Check-only absence observations are disabled');
        try {
            $fixture->rows->flush();
        } finally {
            static::assertFalse($fixture->stored(
                $scoped ? new MappedKey(['tenant' => 'tenant-1', 'id' => 'first']) : 'first',
            ));
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function observations(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['columns', 'present', 'absent'] as $expectation) {
                yield $backend . ' explicit ' . $expectation => [$backend, $expectation, false];
                yield $backend . ' read ' . $expectation => [$backend, $expectation, true];
            }
        }
    }

    #[DataProvider('observations')]
    public function test_observations_without_writes_are_pending_and_reject_storage_changes(
        string $backend,
        string $expectation,
        bool $observe,
    ): void {
        $fixture = $this->fixture($backend);
        $rows = $fixture->rows;
        if ('absent' !== $expectation) {
            $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => null]);
        }
        $register = static function () use ($rows, $expectation, $observe): void {
            $row = $rows->withKey('row');
            if (!$observe) {
                match ($expectation) {
                    'columns' => $row->expectColumnValues(['status' => 'pending', 'note' => null]),
                    'present' => $row->expectPresent(),
                    'absent' => $row->expectAbsent(),
                };

                return;
            }

            if ('columns' === $expectation) {
                static::assertSame(['status' => 'pending', 'note' => null], $row->observe(['status', 'note']));
            } else {
                static::assertSame('present' === $expectation ? ['id' => 'row'] : null, $row->observe([]));
            }
        };
        $register();
        static::assertTrue($rows->hasPendingChanges());
        $fixture->flush();
        static::assertFalse($rows->hasPendingChanges());
        $register();
        if ('absent' === $expectation) {
            static::assertFalse($fixture->stored('row'));
            $fixture->seed(['id' => 'row', 'status' => 'created']);
        } elseif ('present' === $expectation) {
            $fixture->seed(['id' => 'row', 'status' => 'value changes are allowed']);
            $fixture->flush();
            static::assertSame(['status' => 'value changes are allowed'], $fixture->stored('row', ['status']));
            $register();
            $fixture->delete('row');
        } else {
            $fixture->seed(['id' => 'row', 'status' => 'concurrent']);
        }
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    #[DataProvider('backends')]
    public function test_observe_without_a_projection_protects_all_the_read_column_values(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => 'original']);
        $row = $fixture->rows->withKey(['id' => 'row']);
        static::assertSame(['status' => 'pending'], $row->observe(['status']));
        $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => 'allowed']);
        $fixture->flush();

        $row = $fixture->rows->withKey('row');
        static::assertSame($row->get(), $row->observe());
        $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => 'conflict']);

        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    #[DataProvider('backends')]
    public function test_observing_an_unknown_column_keeps_a_blind_upsert(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $key = new MappedKey(['tenant' => 'one', 'owner' => 'order', 'id' => 'new']);
        $row = $rows->withKey($key)->upsert()->setColumn('status', 'draft');

        static::assertSame(['tenant' => 'one', 'owner' => 'order', 'id' => 'new', 'status' => 'draft'], $row->get());
        static::assertSame([], $row->get(['note']));
        static::assertSame([], $row->observe(['note']));
        static::assertSame(['status' => 'draft'], $row->observe(['status', 'note']));
        $plan = $rows->updatePlan()->rowUpdates[0];
        static::assertSame(RowUpdatePlan::MODE_UPSERT, $plan->mode);
        static::assertNull($plan->expectedPresence);
        static::assertSame([], $plan->expectedColumns);

        $fixture->flush($rows);

        static::assertSame(['status' => 'draft'], $fixture->stored($key, ['status']));
    }

    #[DataProvider('backends')]
    public function test_observe_compares_a_stored_null_and_returns_a_buffered_null(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $key = new MappedKey(['tenant' => 'one', 'owner' => 'order', 'id' => 'existing']);
        $fixture->seed([...$key->values, 'status' => 'pending', 'note' => null]);
        $row = $rows->withKey($key)->upsert()->setColumn('status', null);

        static::assertSame(['status' => null, 'note' => null], $row->get(['status', 'note']));
        static::assertSame(['status' => null, 'note' => null], $row->observe(['status', 'note']));
        $plan = $rows->updatePlan()->rowUpdates[0];
        static::assertSame(RowUpdatePlan::MODE_UPDATE, $plan->mode);
        static::assertSame(['note' => null], $plan->expectedColumns);

        $fixture->seed([...$key->values, 'status' => 'pending', 'note' => 'concurrent']);

        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush($rows);
        } finally {
            static::assertSame(
                ['status' => 'pending', 'note' => 'concurrent'],
                $fixture->stored($key, ['status', 'note']),
            );
        }
    }

    #[DataProvider('backends')]
    public function test_observing_a_replacement_only_returns_its_known_values(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $key = new MappedKey(['tenant' => 'one', 'owner' => 'order', 'id' => 'existing']);
        $fixture->seed([...$key->values, 'status' => 'pending', 'note' => 'old']);
        $row = $rows->withKey($key)->delete()->insert()->setColumn('status', 'replacement');

        static::assertSame(['status' => 'replacement'], $row->get(['status', 'note']));
        static::assertSame(['status' => 'replacement'], $row->observe(['status', 'note']));
        $plan = $rows->updatePlan()->rowUpdates[0];
        static::assertSame(RowUpdatePlan::MODE_REPLACE, $plan->mode);
        static::assertSame(RowUpdatePlan::EXPECTED_PRESENT, $plan->expectedPresence);
        static::assertSame([], $plan->expectedColumns);
        $fixture->flush($rows);

        static::assertSame(['status' => 'replacement', 'note' => null], $fixture->stored($key, ['status', 'note']));
    }

    public function test_failed_multi_column_observation_leaves_previous_expectations_intact(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => 'new']);
        $row = $fixture->rows->withKey('row')->expectColumnValue('note', 'original');

        try {
            $row->observe(['status', 'note']);
            static::fail('The previous expectation must be preserved.');
        } catch (ConcurrencyException) {
            static::assertSame(['note' => 'original'], $fixture->rows->updatePlan()->rowUpdates[0]->expectedColumns);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function conditionalWrites(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['update', 'delete'] as $operation) {
                yield $backend . ' ' . $operation => [$backend, $operation];
            }
        }
    }

    #[DataProvider('conditionalWrites')]
    public function test_conditional_writes_keep_the_original_expectation_and_merge_only_written_values(
        string $backend,
        string $operation,
    ): void {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => null]);
        $rows = $fixture->rows;
        $rows->withKey('row')->expectColumnValue('status', 'pending')->setColumn('status', 'paid');
        $row = $rows->withKey('row');
        static::assertSame(['status' => 'paid', 'note' => null], $row->observe(['status', 'note']));
        static::assertSame(
            ['status' => 'pending', 'note' => null],
            $rows->updatePlan()->rowUpdates[0]->expectedColumns,
        );
        $row->setColumn('status', 'shipped')->upsert();
        $fixture->flush();
        static::assertSame(['status' => 'shipped', 'note' => null], $fixture->stored('row', ['status', 'note']));
        $rows->clear();

        $rows->withKey('row')->upsert()->setColumn('status', 'delivered');
        $rows->withKey('row')->expectColumnValue('note', null);
        if ('delete' === $operation) {
            $rows->withKey('row')->delete();
        }
        $fixture->seed(['id' => 'row', 'status' => 'shipped', 'note' => 'concurrent']);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(
                ['status' => 'shipped', 'note' => 'concurrent'],
                $fixture->stored('row', ['status', 'note']),
            );
        }
    }

    public function test_expectations_on_created_or_deleted_rows_use_the_local_view(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'deleted', 'status' => 'pending']);
        $rows = $fixture->rows;
        $rows->withKey('created')->expectAbsent()->setColumn('status', 'new');
        $created = $rows->withKey('created');
        static::assertSame(['status' => 'new'], $created->observe(['status', 'note']));
        $created->setColumn('note', 'draft');
        $rows->withKey('deleted')->expectColumnValue('status', 'pending')->delete();
        static::assertNull($rows->withKey('deleted')->observe([]));
        $fixture->flush();
        static::assertSame(['status' => 'new', 'note' => 'draft'], $fixture->stored('created', ['status', 'note']));
        static::assertFalse($fixture->stored('deleted'));
    }

    /** @return iterable<string, array{\Closure(MappedRowSet): mixed}> */
    public static function localConflicts(): iterable
    {
        yield 'stale buffered value' => [
            static fn(MappedRowSet $rows) => $rows
                ->withKey('row')
                ->expectColumnValue('status', 'old')
                ->setColumn('status', 'new')
                ->expectColumnValue('status', 'old'),
        ];
        yield 'contradictory committed values' => [
            static fn(MappedRowSet $rows) => $rows
                ->withKey('row')
                ->expectColumnValue('status', 'old')
                ->expectColumnValue('status', 'other'),
        ];
        yield 'presence after deletion' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->delete()->expectPresent(),
        ];
        yield 'column after deletion' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->delete()->expectColumnValue('status', 'old'),
        ];
        yield 'write after deletion' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->delete()->setColumn('status', 'new'),
        ];
        yield 'absence after write' => [
            static fn(MappedRowSet $rows) => $rows
                ->withKey('row')
                ->upsert()
                ->setColumn('status', 'new')
                ->expectAbsent(),
        ];
        yield 'presence after absence' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->expectAbsent()->expectPresent(),
        ];
        yield 'absence after presence' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->expectPresent()->expectAbsent(),
        ];
        yield 'column after absence' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->expectAbsent()->expectColumnValue('status', 'old'),
        ];
        yield 'insert after presence' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->expectPresent()->insert(),
        ];
        yield 'unwritten column on inserted row' => [
            static fn(MappedRowSet $rows) => $rows->withKey('row')->insert()->expectColumnValue('status', 'old'),
        ];
    }

    #[DataProvider('localConflicts')]
    public function test_conflicting_local_expectations_fail_before_storage_is_touched(\Closure $prepare): void
    {
        $fixture = $this->fixture();
        $this->expectException(ConcurrencyException::class);
        try {
            $prepare($fixture->rows);
        } finally {
            static::assertFalse($fixture->stored('row'));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function membershipChanges(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['insertion', 'deletion'] as $change) {
                yield $backend . ' ' . $change => [$backend, $change];
            }
        }
    }

    #[DataProvider('membershipChanges')]
    public function test_membership_expectations_detect_both_insertions_and_deletions(
        string $backend,
        string $change,
    ): void {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'first']);
        $fixture->seed(['id' => 'second']);
        $fixture->rows->expectSameRowKeys(['second', 'first']);
        static::assertTrue($fixture->rows->hasPendingChanges());
        $fixture->flush();
        static::assertFalse($fixture->rows->hasPendingChanges());
        $fixture->rows->expectSameRowKeys(['second', 'first']);
        if ('insertion' === $change) {
            $fixture->seed(['id' => 'late']);
        } else {
            $fixture->delete('second');
        }
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    #[DataProvider('backends')]
    public function test_membership_expectations_allow_local_writes_but_keep_other_rows_guarded(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'existing', 'status' => 'old']);
        $rows = $fixture->rows;
        $rows->expectSameRowKeys(['existing']);
        $rows->withKey('existing')->expectColumnValue('status', 'old')->setColumn('status', 'updated');
        $rows->withKey('added')->expectAbsent()->setColumn('status', 'new');
        $rows->expectSameRowKeys(['existing', 'added']);
        $fixture->flush();
        static::assertSame(['status' => 'updated'], $fixture->stored('existing', ['status']));
        static::assertSame(['status' => 'new'], $fixture->stored('added', ['status']));
        $rows->clear();

        $rows->withKey('another')->upsert()->setColumn('status', 'new');
        $rows->expectSameRowKeys(['existing', 'added', 'another']);
        // Re-observation must retain the committed expectation even when earlier writes decided some keys.
        $rows->expectSameRowKeys(['existing', 'added', 'another']);
        $fixture->seed(['id' => 'late']);
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    public function test_duplicate_expected_keys_are_rejected(): void
    {
        $rows = $this->fixture()->rows;
        $this->expectException(\InvalidArgumentException::class);
        $rows->expectSameRowKeys(['row', 'row']);
    }

    public function test_a_second_membership_expectation_cannot_discard_the_first(): void
    {
        $rows = $this->fixture()->rows;
        $rows->expectSameRowKeys(['first', 'second']);
        $rows->withKey('local')->upsert()->setColumn('status', 'new');
        $this->expectException(ConcurrencyException::class);
        $rows->expectSameRowKeys(['second', 'local']);
    }

    #[DataProvider('backends')]
    public function test_owner_membership_observations_precede_mutations_and_survive_local_reconciliation(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $one = new MappedKey(['tenant' => 'one', 'owner' => 'order']);
        $two = new MappedKey(['tenant' => 'two', 'owner' => 'order']);
        $oneLine = $one->mergedWith(new MappedKey(['id' => 'line']));
        $twoLine = $two->mergedWith(new MappedKey(['id' => 'line']));
        $fixture->seed([...$twoLine->values, 'status' => 'old']);

        static::assertSame([], $rows->observeKeysForOwner($one->values));
        static::assertEquals([$twoLine], $rows->observeKeysForOwner($two));
        $rows->reconcileForOwner($one->values, []);
        $rows->withKey($twoLine)->setColumn('status', 'local');
        $rows->expectSameRowKeysForOwner($two->values, [$twoLine->values]);
        $fixture->seed([...$oneLine->values, 'status' => 'concurrent']);

        try {
            $fixture->flush($rows);
            static::fail('Reconciliation must retain the observation of the initially empty collection.');
        } catch (ConcurrencyException) {
            static::assertSame(['status' => 'concurrent'], $fixture->stored($oneLine, ['status']));
            static::assertSame(['status' => 'old'], $fixture->stored($twoLine, ['status']));
            static::assertTrue($rows->hasPendingChanges());
        }

        $rows->clear();
        static::assertEquals([$oneLine], $rows->observeKeysForOwner($one->values));
        $fixture->seed([...$oneLine->values, 'status' => 'value change only']);
        $fixture->flush($rows);
        static::assertFalse($rows->hasPendingChanges());
        static::assertSame(['status' => 'value change only'], $fixture->stored($oneLine, ['status']));
    }
}
