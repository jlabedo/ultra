<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class ColumnValueRoundTripTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, string, mixed, mixed}> */
    public static function values(): iterable
    {
        yield 'escaped text array with nulls' => ['tags', 'textArray', ['alpha', 'comma,value', 'quote"value', 'back\\slash', '', 'NULL', null], ['alpha', 'comma,value', 'quote"value', 'back\\slash', '', 'NULL', null]];
        yield 'integer array with nulls' => ['tags', 'intArray', [1, null, -7], [1, null, -7]];
        yield 'UUID array' => ['tags', 'uuidArray', ['00000000-0000-0000-0000-000000000001'], ['00000000-0000-0000-0000-000000000001']];
        $json = ['anyOf' => [['documentType' => 'passport'], ['allOf' => [['documentType' => 'driver_license']]]]];
        yield 'nested JSON' => ['payload', 'jsonb', $json, $json];
        yield 'ISO date' => ['expires_on', 'date', '2026-05-25', '2026-05-25'];
        yield 'date object' => ['expires_on', 'date', new \DateTimeImmutable('2026-05-25'), '2026-05-25'];
        yield 'integer stored as text' => ['note', 'text', 42, '42'];
    }

    #[DataProvider('values')]
    public function test_column_values_survive_single_and_multiple_writes_and_optimistic_updates(string $column, string $kind, mixed $input, mixed $expected): void
    {
        $type = PostgresMappedColumnType::{$kind}();
        $fixture = $this->fixture('postgres', columnTypes: [$column => $type]);
        $rows = $fixture->rows;
        $rows->withKey('first')->insert()->setColumn($column, $input);
        $fixture->flush();
        $rows->clear();
        self::assertSame([$column => $expected], $rows->row('first', [$column]));
        self::assertSame([$column => $expected], $rows->rows([$column])[0]->columns);
        $replacement = str_ends_with($type->sqlType, '[]') ? [] : null;
        $rows->withKey('first')->expectColumnValue($column, $expected)->setColumn($column, $replacement);
        $rows->withKey('second')->insert()->setColumn($column, $input);
        $rows->withKey('third')->insert()->setColumn($column, null);
        $fixture->flush();
        $rows->clear();
        self::assertSame([$column => $replacement], $rows->row('first', [$column]));
        self::assertSame([$column => $expected], $rows->row('second', [$column]));
        self::assertSame([$column => null], $rows->row('third', [$column]));
        self::assertSame([$column => $expected], $fixture->stored('second', [$column]));
        if ('date' === $kind) {
            self::assertSame('date', $fixture->connection()->fetchOne('SELECT pg_typeof(expires_on)::text FROM '.$fixture->connection()->quoteSingleIdentifier($fixture->tableName).' LIMIT 1'));
        }
    }

    public function test_array_upserts_preserve_values_and_reject_stale_array_expectations(): void
    {
        $fixture = $this->fixture('postgres', columnTypes: ['tags' => PostgresMappedColumnType::textArray()]);
        $rows = $fixture->rows;
        $rows->withKey('first')->upsert()->setColumn('tags', ['alpha']);
        $rows->withKey('second')->upsert()->setColumn('tags', ['beta', 'gamma']);
        $fixture->flush();
        $rows->clear();
        self::assertSame(['tags' => ['alpha']], $rows->row('first', ['tags']));
        self::assertSame(['tags' => ['beta', 'gamma']], $rows->row('second', ['tags']));
        $rows->withKey('first')->expectColumnValue('tags', ['stale'])->setColumn('tags', []);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            self::assertSame(['tags' => ['alpha']], $fixture->stored('first', ['tags']));
        }
    }

    public function test_boolean_values_preserve_false_in_updates_and_multiple_inserts(): void
    {
        $fixture = $this->fixture('postgres');
        $rows = $fixture->rows;
        $rows->withKey('first')->insert()->setColumn('active', false);
        $fixture->flush();
        $rows->clear();
        $rows->withKey('first')->expectColumnValue('active', false)->setColumn('active', true);
        $rows->withKey('second')->insert()->setColumn('active', false);
        $rows->withKey('third')->insert()->setColumn('active', false);
        $fixture->flush();
        $rows->clear();
        self::assertSame([true, false, false], array_map(static fn ($row) => $row->columns['active'], $rows->rows(['active'])));
    }
}
