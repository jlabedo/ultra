<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class WriteSemanticsTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_insert_and_upsert_create_rows_and_updates_preserve_unspecified_columns(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $rows = $fixture->rows;
        $rows->withKey('inserted')->insert()->setColumn('status', 'first');
        $rows->withKey('inserted')->setColumn('note', 'preserved');
        $rows->withKey('upserted')->setColumn('status', 'new')->upsert();
        $rows->withKey('absent')->expectAbsent()->setColumn('status', 'created');
        $fixture->flush();
        self::assertSame(['status' => 'first', 'note' => 'preserved'], $fixture->stored('inserted', ['status', 'note']));
        self::assertSame(['status' => 'new'], $fixture->stored('upserted', ['status']));
        self::assertSame(['status' => 'created'], $fixture->stored('absent', ['status']));
        $rows->clear();

        $rows->withKey('inserted')->expectColumnValue('status', 'first')->setColumn('status', 'second');
        $rows->withKey('upserted')->upsert()->setColumn('note', 'added');
        $fixture->flush();
        self::assertSame(['status' => 'second', 'note' => 'preserved'], $fixture->stored('inserted', ['status', 'note']));
        self::assertSame(['status' => 'new', 'note' => 'added'], $fixture->stored('upserted', ['status', 'note']));
    }

    #[DataProvider('backends')]
    public function test_insert_rejects_an_existing_row_without_overwriting_it(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'existing', 'status' => 'original']);
        $fixture->rows->withKey('existing')->insert()->setColumn('status', 'replacement');
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            self::assertSame(['status' => 'original'], $fixture->stored('existing', ['status']));
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
        self::assertFalse($fixture->stored('first'));
        self::assertFalse($fixture->stored('second'));
        self::assertSame(['status' => 'pending'], $fixture->stored('untouched', ['status']));
        $fixture->rows->clear();
        $fixture->rows->withKey('untouched')->delete();
        $fixture->rows->withKey('missing')->delete();
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            self::assertSame(['status' => 'pending'], $fixture->stored('untouched', ['status']));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function replacements(): iterable
    {
        foreach (['memory', 'postgres'] as $backend) {
            foreach (['insert', 'upsert'] as $operation) {
                yield $backend.' '.$operation => [$backend, $operation];
            }
        }
    }

    #[DataProvider('replacements')]
    public function test_replacement_discards_old_columns_and_preserves_the_original_expectation(string $backend, string $operation): void
    {
        $fixture = $this->fixture($backend);
        $fixture->seed(['id' => 'row', 'status' => 'old', 'note' => 'discarded']);
        $rows = $fixture->rows;
        $rows->withKey('row')->expectColumnValue('status', 'old')->delete();
        $rows->withKey('row')->{$operation}()->setColumn('status', 'new');
        $fixture->flush();
        self::assertSame(['status' => 'new', 'note' => null], $fixture->stored('row', ['status', 'note']));
        $rows->clear();

        $rows->withKey('row')->expectColumnValue('status', 'new')->delete();
        $rows->withKey('row')->{$operation}()->setColumn('status', 'replacement');
        $fixture->seed(['id' => 'row', 'status' => 'concurrent']);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            self::assertSame(['status' => 'concurrent'], $fixture->stored('row', ['status']));
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
        self::assertFalse($fixture->stored('row'));
        self::assertFalse($fixture->stored('cancelled'));
    }
}
