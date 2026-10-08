<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
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

    /** @return iterable<string, array{string, string}> */
    public static function observations(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['columns', 'present', 'absent'] as $expectation) {
                yield $backend . ' ' . $expectation => [$backend, $expectation];
            }
        }
    }

    #[DataProvider('observations')]
    public function test_observations_without_writes_are_pending_and_reject_storage_changes(
        string $backend,
        string $expectation,
    ): void {
        $fixture = $this->fixture($backend);
        $rows = $fixture->rows;
        if ('absent' !== $expectation) {
            $fixture->seed(['id' => 'row', 'status' => 'pending', 'note' => null]);
        }
        match ($expectation) {
            'columns' => $rows->withKey('row')->expectColumnValues(['status' => 'pending', 'note' => null]),
            'present' => $rows->withKey('row')->expectPresent(),
            'absent' => $rows->withKey('row')->expectAbsent(),
        };
        static::assertTrue($rows->hasPendingChanges());
        $fixture->flush();
        if ('absent' === $expectation) {
            static::assertFalse($fixture->stored('row'));
            $fixture->seed(['id' => 'row', 'status' => 'created']);
        } elseif ('present' === $expectation) {
            $fixture->seed(['id' => 'row', 'status' => 'value changes are allowed']);
            $fixture->flush();
            static::assertSame(['status' => 'value changes are allowed'], $fixture->stored('row', ['status']));
            $fixture->delete('row');
        } else {
            $fixture->seed(['id' => 'row', 'status' => 'concurrent']);
        }
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
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
        $rows
            ->withKey('row')
            ->expectColumnValue('status', 'paid')
            ->expectColumnValue('note', null)
            ->setColumn('status', 'shipped')
            ->upsert();
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
        $rows
            ->withKey('created')
            ->expectPresent()
            ->expectColumnValue('status', 'new')
            ->expectColumnValue('note', null)
            ->setColumn('note', 'draft');
        $rows->withKey('deleted')->expectColumnValue('status', 'pending')->delete();
        $rows->withKey('deleted')->expectAbsent();
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
}
