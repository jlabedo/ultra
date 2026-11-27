<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\Codec\IntegerCodec;
use Ultra\MappedTable\Codec\TextCodec;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class WriteSemanticsTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_increments_accumulate_on_the_current_row_or_initialize_an_upsert(string $backend): void
    {
        $fixture = $this->stock($backend);
        $rows = $fixture->rows;
        $fixture->seed(['id' => 'existing', 'units' => 10, 'event_count' => 2, 'note' => 'keep']);
        $rows->withKey('existing')->upsert()->incrementColumn('units', 5)->incrementColumn('event_count', 1);
        $rows->withKey('existing')->incrementColumn('units', -2)->incrementColumn('event_count', 1);
        $rows->withKey('new')->upsert()->incrementColumn('units', -4)->incrementColumn('event_count', 2);
        $rows->withKey('new')->incrementColumn('units', 4);
        $fixture->seed(['id' => 'existing', 'units' => 30, 'event_count' => 4, 'note' => 'keep']);

        $fixture->flush();

        static::assertSame(
            ['units' => 33, 'event_count' => 6, 'note' => 'keep'],
            $fixture->stored('existing', ['units', 'event_count', 'note']),
        );
        static::assertSame(['units' => 0, 'event_count' => 2], $fixture->stored('new', ['units', 'event_count']));
        static::assertFalse($rows->hasPendingChanges());
    }

    #[DataProvider('backends')]
    public function test_increments_without_creation_apply_to_existing_rows_and_fail_on_a_missing_row(string $backend): void
    {
        $fixture = $this->stock($backend);
        $fixture->seed(['id' => 'a-present', 'units' => -2_147_483_648, 'event_count' => 0]);
        $fixture->rows->withKey('a-present')->incrementColumn('units', 4_294_967_295);
        $fixture->flush();
        static::assertSame(['units' => 2_147_483_647], $fixture->stored('a-present', ['units']));

        $fixture->rows->withKey('a-present')->incrementColumn('units', -2);
        $fixture->rows->withKey('b-missing')->incrementColumn('units', 0);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(['units' => 2_147_483_647], $fixture->stored('a-present', ['units']));
            static::assertFalse($fixture->stored('b-missing', ['units']));
        }
    }

    #[DataProvider('backends')]
    public function test_deletion_discards_increments_and_replacement_starts_at_zero(string $backend): void
    {
        $fixture = $this->stock($backend);
        $fixture->seed(['id' => 'row', 'units' => 40, 'event_count' => 7]);
        $fixture
            ->rows
            ->withKey('row')
            ->incrementColumn('units', 3)
            ->delete()
            ->upsert()
            ->incrementColumn('units', -5)
            ->incrementColumn('event_count', 1);
        $fixture->rows->withKey('cancelled')->insert()->incrementColumn('units', 9)->delete();
        $fixture->flush();

        static::assertSame(['units' => -5, 'event_count' => 1], $fixture->stored('row', ['units', 'event_count']));
        static::assertFalse($fixture->stored('cancelled', ['units']));
    }

    public function test_increment_rejects_keys_nullable_untyped_and_non_integer_columns(): void
    {
        $fixture = $this->fixture();
        $integer = new IntegerCodec();
        $definition = new MappedRowSetDefinition(
            $fixture->tableName,
            ['id', 'nullable', 'untyped', 'integer', 'text'],
            ['id'],
            codecs: ['id' => $integer, 'nullable' => $integer, 'integer' => $integer, 'text' => new TextCodec()],
            nullable: ['nullable'],
        );
        $rows = $fixture->adapter->compileRowSet($definition)();
        foreach (['id', 'nullable', 'untyped', 'text', 'missing'] as $column) {
            try {
                $rows->withKey(1)->incrementColumn($column, 1);
                static::fail('Expected increment to reject ' . $column);
            } catch (\InvalidArgumentException) {
                static::assertFalse($rows->hasPendingChanges());
            }
        }

        $unknownNullability = new MappedRowSetDefinition(
            'unknown_nullability',
            ['id', 'integer'],
            ['id'],
            codecs: ['integer' => $integer],
        );
        $rows = $fixture->adapter->compileRowSet($unknownNullability)();
        $this->expectException(\InvalidArgumentException::class);
        $rows->withKey(1)->incrementColumn('integer', 1);
    }

    public function test_overflowing_a_buffered_delta_leaves_its_previous_value_intact(): void
    {
        $rows = $this->stock('memory')->rows;
        foreach (['minimum' => PHP_INT_MIN, 'maximum' => PHP_INT_MAX] as $id => $limit) {
            $row = $rows->withKey($id)->insert()->incrementColumn('event_count', $limit);
            try {
                $row->incrementColumn('event_count', $limit <=> 0);
                static::fail('The accumulated delta must stay within the integer range.');
            } catch (\OverflowException) {
                static::assertSame(['event_count' => $limit], $row->get(['event_count']));
            }
        }
    }

    public function test_postgres_checks_the_upsert_candidate_even_when_the_updated_row_would_pass(): void
    {
        $fixture = $this->stock('postgres');
        $fixture->connection()->executeStatement('ALTER TABLE ' . $fixture->tableName . ' ADD CHECK (units >= 0)');
        $fixture->seed(['id' => 'existing', 'units' => 10, 'event_count' => 1]);
        $fixture->rows->withKey('existing')->upsert()->incrementColumn('units', -3)->incrementColumn('event_count', 1);

        try {
            $fixture->flush();
            static::fail('The candidate for insertion violates the check.');
        } catch (DriverException $exception) {
            static::assertSame('23514', $exception->getSQLState());
            static::assertSame(['units' => 10], $fixture->stored('existing', ['units']));
        }
        $fixture->unit()->clear();
        $fixture->rows->withKey('existing')->incrementColumn('units', -3);
        $fixture->flush();

        static::assertSame(['units' => 7], $fixture->stored('existing', ['units']));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function creationBackends(): iterable
    {
        yield 'in memory' => ['memory', true];
        yield 'in memory without absence protection' => ['memory', false];
        yield 'Postgres' => ['postgres', true];
        yield 'Postgres without absence protection' => ['postgres', false];
    }

    #[DataProvider('creationBackends')]
    public function test_insert_and_upsert_create_rows_and_updates_preserve_unspecified_columns(
        string $backend,
        bool $allowAbsentObservations,
    ): void {
        $fixture = $this->fixture($backend, allowAbsentObservations: $allowAbsentObservations);
        $rows = $fixture->rows;
        $rows->withKey('inserted')->insert()->setColumn('status', 'first');
        $rows->withKey('inserted')->setColumn('note', 'preserved');
        $rows->withKey('upserted')->setColumn('status', 'new')->upsert();
        $rows->withKey('absent')->expectAbsent()->insert()->setColumn('status', 'created');
        $fixture->flush();
        static::assertSame(
            ['status' => 'first', 'note' => 'preserved'],
            $fixture->stored('inserted', ['status', 'note']),
        );
        static::assertSame(['status' => 'new'], $fixture->stored('upserted', ['status']));
        static::assertSame(['status' => 'created'], $fixture->stored('absent', ['status']));
        $rows->clear();

        $rows->withKey('inserted')->expectColumnValue('status', 'first')->setColumn('status', 'second');
        $rows->withKey('upserted')->upsert()->setColumn('note', 'added');
        $fixture->flush();
        static::assertSame(
            ['status' => 'second', 'note' => 'preserved'],
            $fixture->stored('inserted', ['status', 'note']),
        );
        static::assertSame(['status' => 'new', 'note' => 'added'], $fixture->stored('upserted', ['status', 'note']));
    }

    #[DataProvider('creationBackends')]
    public function test_insert_rejects_an_existing_row_without_overwriting_it(
        string $backend,
        bool $allowAbsentObservations,
    ): void {
        $fixture = $this->fixture($backend, allowAbsentObservations: $allowAbsentObservations);
        $fixture->seed(['id' => 'existing', 'status' => 'original']);
        $fixture->rows->withKey('existing')->insert()->setColumn('status', 'replacement');
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(['status' => 'original'], $fixture->stored('existing', ['status']));
        }
    }

    #[DataProvider('backends')]
    public function test_multiple_deletions_preserve_other_rows_and_roll_back_when_a_row_is_missing(string $backend): void
    {
        $fixture = $this->fixture($backend);
        foreach (['first', 'second', 'untouched'] as $id) {
            $fixture->seed(['id' => $id, 'status' => 'pending']);
        }
        $fixture->rows->withKey('first')->delete();
        $fixture->rows->withKey('second')->delete();
        $fixture->flush();
        static::assertFalse($fixture->stored('first'));
        static::assertFalse($fixture->stored('second'));
        static::assertSame(['status' => 'pending'], $fixture->stored('untouched', ['status']));
        $fixture->rows->clear();
        $fixture->rows->withKey('untouched')->delete();
        $fixture->rows->withKey('missing')->delete();
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(['status' => 'pending'], $fixture->stored('untouched', ['status']));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function replacements(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['insert', 'upsert'] as $operation) {
                yield $backend . ' ' . $operation => [$backend, $operation];
            }
        }
    }

    #[DataProvider('replacements')]
    public function test_replacement_discards_old_columns_and_preserves_the_original_expectation(
        string $backend,
        string $operation,
    ): void {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'row', 'status' => 'old', 'note' => 'discarded']);
        $rows = $fixture->rows;
        $rows->withKey('row')->expectColumnValue('status', 'old')->delete();
        $rows->withKey('row')->{$operation}()->setColumn('status', 'new');
        static::assertSame(['status' => 'new'], $rows->withKey('row')->get(['status', 'note']));
        $fixture->flush();
        static::assertSame(['status' => 'new', 'note' => null], $fixture->stored('row', ['status', 'note']));
        $rows->clear();

        $rows->withKey('row')->expectColumnValue('status', 'new')->delete();
        $rows->withKey('row')->{$operation}()->setColumn('status', 'replacement');
        $fixture->seed(['id' => 'row', 'status' => 'concurrent']);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(['status' => 'concurrent'], $fixture->stored('row', ['status']));
        }
    }

    public function test_later_operations_merge_or_cancel_buffered_changes(): void
    {
        $fixture = $this->fixture();
        $fixture->seed(['id' => 'row', 'status' => 'old', 'note' => 'draft']);
        $rows = $fixture->rows;
        $rows->withKey('row')->expectColumnValue('status', 'old')->setColumn('note', 'changed');
        $rows->withKey('row')->expectColumnValue('note', 'changed')->delete();
        $rows->withKey('cancelled')->insert()->setColumn('status', 'new');
        $rows->withKey('cancelled')->delete();
        $fixture->flush();
        static::assertFalse($fixture->stored('row'));
        static::assertFalse($fixture->stored('cancelled'));
    }

    #[DataProvider('backends')]
    public function test_deleting_expected_absent_rows_only_checks_absence(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->rows->withKey('absent')->expectAbsent()->delete();
        $fixture->flush();
        static::assertFalse($fixture->stored('absent'));

        static::assertFalse($fixture->rows->hasPendingChanges());
        $fixture->rows->withKey('absent')->expectAbsent()->delete();
        $fixture->seed(['id' => 'absent', 'status' => 'competing']);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(['status' => 'competing'], $fixture->stored('absent', ['status']));
        }
    }
}
