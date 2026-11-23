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
    /** @return iterable<string, array{string, PostgresMappedColumnType, mixed, mixed}> */
    public static function values(): iterable
    {
        yield 'escaped text array with nulls' => [
            'tags',
            PostgresMappedColumnType::TextArray,
            ['alpha', 'comma,value', 'quote"value', 'back\\slash', '', 'NULL', null],
            ['alpha', 'comma,value', 'quote"value', 'back\\slash', '', 'NULL', null],
        ];
        yield 'integer array with nulls' => ['tags', PostgresMappedColumnType::IntArray, [1, null, -7], [1, null, -7]];
        yield 'UUID array' => [
            'tags',
            PostgresMappedColumnType::UuidArray,
            ['00000000-0000-0000-0000-000000000001'],
            ['00000000-0000-0000-0000-000000000001'],
        ];
        $json = ['anyOf' => [['documentType' => 'passport'], ['allOf' => [['documentType' => 'driver_license']]]]];
        yield 'nested JSON' => ['payload', PostgresMappedColumnType::Jsonb, $json, $json];
        yield 'ISO date' => ['expires_on', PostgresMappedColumnType::Date, '2026-05-25', '2026-05-25'];
        yield 'wall-clock timestamp' => [
            'expires_on',
            PostgresMappedColumnType::Timestamp,
            '2026-05-25T12:34:56.123456+02:00',
            '2026-05-25T12:34:56.123456+00:00',
        ];
        yield 'timestamp instant with offset' => [
            'expires_on',
            PostgresMappedColumnType::TimestampWithTimeZone,
            '2026-05-25T12:34:56.654321+02:00',
            '2026-05-25T10:34:56.654321+00:00',
        ];
        yield 'timestamp instant with negative offset' => [
            'expires_on',
            PostgresMappedColumnType::TimestampWithTimeZone,
            '2026-05-25T12:34:56.123456-03:00',
            '2026-05-25T15:34:56.123456+00:00',
        ];
        yield 'numeric text' => ['note', PostgresMappedColumnType::Text, '42', '42'];
    }

    #[DataProvider('values')]
    public function test_column_values_survive_single_and_multiple_writes_and_optimistic_updates(
        string $column,
        PostgresMappedColumnType $type,
        mixed $input,
        mixed $expected,
    ): void {
        $fixture = $this->fixture('postgres', columnTypes: [$column => $type]);
        $rows = $fixture->rows;
        $rows->withKey('first')->insert()->setColumn($column, $input);
        $fixture->flush();
        $rows->clear();
        static::assertSame([$column => $expected], $rows->withKey('first')->get([$column]));
        $replacement = str_ends_with($type->value, '[]') ? [] : null;
        $rows->withKey('first')->expectColumnValue($column, $expected)->setColumn($column, $replacement);
        $rows->withKey('second')->insert()->setColumn($column, $input);
        $rows->withKey('third')->insert()->setColumn($column, null);
        $fixture->flush();
        $rows->clear();
        static::assertSame([$column => $replacement], $rows->withKey('first')->get([$column]));
        static::assertSame([$column => $expected], $rows->withKey('second')->get([$column]));
        static::assertSame([$column => null], $rows->withKey('third')->get([$column]));
        static::assertSame([$column => $expected], $fixture->stored('second', [$column]));
        if (PostgresMappedColumnType::Date === $type) {
            static::assertSame(
                'date',
                $fixture
                    ->connection()
                    ->fetchOne(
                        'SELECT pg_typeof(expires_on)::text FROM '
                        . $fixture->connection()->quoteSingleIdentifier($fixture->tableName)
                        . ' LIMIT 1',
                    ),
            );
        }
    }

    public function test_array_upserts_preserve_values_and_reject_stale_array_expectations(): void
    {
        $fixture = $this->fixture('postgres', columnTypes: ['tags' => PostgresMappedColumnType::TextArray]);
        $rows = $fixture->rows;
        $rows->withKey('first')->upsert()->setColumn('tags', ['alpha']);
        $rows->withKey('second')->upsert()->setColumn('tags', ['beta', 'gamma']);
        $fixture->flush();
        $rows->clear();
        static::assertSame(['tags' => ['alpha']], $rows->withKey('first')->get(['tags']));
        static::assertSame(['tags' => ['beta', 'gamma']], $rows->withKey('second')->get(['tags']));
        $rows->withKey('first')->expectColumnValue('tags', ['stale'])->setColumn('tags', []);
        $this->expectException(ConcurrencyException::class);
        try {
            $fixture->flush();
        } finally {
            static::assertSame(['tags' => ['alpha']], $fixture->stored('first', ['tags']));
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
        static::assertSame(['active' => true], $rows->withKey('first')->get(['active']));
        static::assertSame(['active' => false], $rows->withKey('second')->get(['active']));
        static::assertSame(['active' => false], $rows->withKey('third')->get(['active']));
    }
}
