<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\JsonNull;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class ColumnValueRoundTripTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, string, mixed, mixed}> */
    public static function values(): iterable
    {
        yield 'escaped text array with nulls' => [
            'tags',
            'text[]',
            ['alpha', 'comma,value', 'quote"value', 'back\\slash', '', 'NULL', null],
            ['alpha', 'comma,value', 'quote"value', 'back\\slash', '', 'NULL', null],
        ];
        yield 'integer array with nulls' => ['tags', 'int4[]', [1, null, -7], [1, null, -7]];
        yield 'UUID array' => [
            'tags',
            'uuid[]',
            ['{ABCDEF00000000000000000000000001}'],
            ['abcdef00-0000-0000-0000-000000000001'],
        ];
        $json = ['anyOf' => [['documentType' => 'passport'], ['allOf' => [['documentType' => 'driver_license']]]]];
        yield 'nested JSON' => ['payload', 'jsonb', $json, $json];
        yield 'ISO date' => ['expires_on', 'date', '2026-05-25', '2026-05-25'];
        yield 'wall-clock timestamp' => [
            'expires_on',
            'timestamp',
            '2026-05-25T12:34:56.123456+02:00',
            '2026-05-25T12:34:56.123456+00:00',
        ];
        yield 'timestamp instant with offset' => [
            'expires_on',
            'timestamptz',
            '2026-05-25T12:34:56.654321+02:00',
            '2026-05-25T10:34:56.654321+00:00',
        ];
        yield 'timestamp instant with negative offset' => [
            'expires_on',
            'timestamptz',
            '2026-05-25T12:34:56.123456-03:00',
            '2026-05-25T15:34:56.123456+00:00',
        ];
        yield 'numeric text' => ['note', 'text', '42', '42'];
    }

    /** @return iterable<string, array{string, string, string, mixed, mixed}> */
    public static function valuesByBackend(): iterable
    {
        foreach (self::backends() as $backend => [$storage]) {
            foreach (self::values() as $case => $value) {
                yield $case . ' in ' . $backend => [$storage, ...$value];
            }
        }
    }

    #[DataProvider('valuesByBackend')]
    public function test_column_values_survive_single_and_multiple_writes_and_optimistic_updates(
        string $backend,
        string $column,
        string $type,
        mixed $input,
        mixed $expected,
    ): void {
        $fixture = $this->fixture($backend, columnTypes: [$column => $type]);
        $rows = $fixture->rows;
        $rows->withKey('first')->insert()->setColumn($column, $input);
        $fixture->flush();
        $rows->clear();
        static::assertSame([$column => $expected], $rows->withKey('first')->get([$column]));
        $replacement = str_ends_with($type, '[]') ? [] : null;
        $rows->withKey('first')->expectColumnValue($column, $expected)->setColumn($column, $replacement);
        $rows->withKey('second')->insert()->setColumn($column, $input);
        $rows->withKey('third')->insert()->setColumn($column, null);
        $fixture->flush();
        $rows->clear();
        static::assertSame([$column => $replacement], $rows->withKey('first')->get([$column]));
        static::assertSame([$column => $expected], $rows->withKey('second')->get([$column]));
        static::assertSame([$column => null], $rows->withKey('third')->get([$column]));
        static::assertSame([$column => $expected], $fixture->stored('second', [$column]));
        if ('postgres' === $backend && 'date' === $type) {
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

    #[DataProvider('backends')]
    public function test_uuid_aliases_address_the_same_buffered_and_committed_row(string $backend): void
    {
        $fixture = $this->fixture($backend, columnTypes: ['id' => 'uuid']);
        $rows = $fixture->rows;
        $canonical = 'abcdef00-0000-0000-0000-000000000001';
        $alias = '{ABCDEF00000000000000000000000001}';

        $rows->withKey($alias)->insert()->setColumn('note', 'pending');
        $rows->withKey($canonical)->expectColumnValues(['id' => $alias, 'note' => 'pending']);
        static::assertCount(1, $rows->updatePlan()->rowUpdates);
        $fixture->flush();
        $rows->clear();

        static::assertSame([['id' => $canonical], ['id' => $canonical]], $rows->observeMany([$alias, $canonical], []));
        $rows->expectSameRowKeys([$alias]);
        $rows->withKey($alias)->setColumn('note', 'changed');
        $fixture->flush();
        static::assertSame(['note' => 'changed'], $fixture->newRows()->withKey($canonical)->get(['note']));
    }

    #[DataProvider('backends')]
    public function test_timestamp_precision_and_instant_equality_agree_in_the_buffer_and_in_storage(string $backend): void
    {
        $fixture = $this->fixture($backend, columnTypes: ['expires_on' => 'timestamp(3) with time zone']);
        $rows = $fixture->rows;
        $value = '2026-05-25T12:34:56.999500+02:00';
        $alias = '2026-05-25T05:34:57.000000-05:00';
        $rows->withKey('entry')->insert()->setColumn('expires_on', $value)->expectColumnValue('expires_on', $alias);
        static::assertSame(
            ['expires_on' => '2026-05-25T10:34:57.000000+00:00'],
            $rows->withKey('entry')->get([
                'expires_on',
            ]),
        );
        $fixture->flush();
        $rows->clear();

        $rows->withKey('entry')->observe(['expires_on']);
        $rows->withKey('entry')->expectColumnValue('expires_on', $alias)->setColumn('expires_on', $value);
        static::assertFalse($rows->updatePlan()->rowUpdates[0]->writes());
        $fixture->flush();

        $rows->withKey('entry')->expectColumnValue('expires_on', '2026-05-25T10:34:57.001+00:00');
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    #[DataProvider('backends')]
    public function test_jsonb_preserves_objects_lists_scalars_and_both_kinds_of_null(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $rows = $fixture->rows;
        $document = [
            'values' => [
                (object) [],
                [],
                (object) ['0' => 'a'],
                ['a'],
                null,
                false,
                0.125,
                '0',
                (object) [1 => 'b', 0 => 'a'],
            ],
        ];
        $rows->withKey('doc')->insert()->setColumn('payload', $document);
        $rows->withKey('json')->insert()->setColumn('payload', JsonNull::Value);
        $rows->withKey('sql')->insert()->setColumn('payload', null);
        $rows->withKey('scalar')->insert()->setColumn('payload', 'a string');
        $fixture->flush();
        $rows->clear();

        $received = $rows->withKey('doc')->observe(['payload'])['payload'];
        static::assertEquals($document, $received);
        static::assertInstanceOf(\stdClass::class, $received['values'][0]);
        static::assertSame([], $received['values'][1]);
        static::assertInstanceOf(\stdClass::class, $received['values'][2]);
        static::assertSame(['a'], $received['values'][3]);
        static::assertInstanceOf(\stdClass::class, $received['values'][8]);
        static::assertSame(['payload' => 'a string'], $rows->withKey('scalar')->get(['payload']));
        static::assertSame(['payload' => JsonNull::Value], $rows->withKey('json')->get(['payload']));
        static::assertSame(['payload' => null], $rows->withKey('sql')->get(['payload']));

        // Mutating a returned object cannot change independently recorded expectations.
        $received['values'][0]->changed = true;
        $rows->withKey('doc')->expectColumnValue('payload', $document);
        $rows->withKey('json')->expectColumnValue('payload', JsonNull::Value);
        $rows->withKey('sql')->expectColumnValue('payload', null);
        $fixture->flush();

        $rows->withKey('json')->expectColumnValue('payload', null);
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    #[DataProvider('backends')]
    public function test_jsonb_expectations_compare_object_values_and_preserve_list_order(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $rows = $fixture->rows;
        $rows
            ->withKey('doc')
            ->insert()
            ->setColumn('payload', ['list' => [1, 2], 'number' => 1.0]);
        $same = ['number' => 1, 'list' => [1, 2]];
        $rows->withKey('doc')->expectColumnValue('payload', $same);
        $fixture->flush();
        $rows->clear();

        $rows->withKey('doc')->expectColumnValue('payload', $same);
        $rows->withKey('doc')->setColumn('payload', ['list' => [1, 2], 'number' => 1.0]);
        static::assertFalse($rows->updatePlan()->rowUpdates[0]->writes());
        $fixture->flush();

        $rows->withKey('doc')->expectColumnValue('payload', ['number' => 1, 'list' => [2, 1]]);
        $this->expectException(ConcurrencyException::class);
        $fixture->flush();
    }

    /** @return iterable<string, array{string}> */
    public static function unrepresentableNumbers(): iterable
    {
        yield 'large integer' => ['9223372036854775809'];
        yield 'precise decimal' => ['0.10000000000000001'];
        yield 'float overflow' => ['1e500'];
        yield 'float underflow' => ['1e-500'];
    }

    #[DataProvider('unrepresentableNumbers')]
    public function test_reading_jsonb_rejects_numbers_that_would_change_on_round_trip(string $number): void
    {
        $fixture = $this->fixture('postgres');
        $fixture->seed(['id' => 'doc', 'payload' => '{"inside":[' . $number . ']}']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('loses precision in PHP');
        $fixture->rows->withKey('doc')->observe(['payload']);
    }

    public function test_reading_jsonb_retains_an_exact_native_integer_with_decimal_scale(): void
    {
        $fixture = $this->fixture('postgres');
        $fixture->seed(['id' => 'doc', 'payload' => '{"number":' . PHP_INT_MAX . '.00}']);
        $row = $fixture->rows->withKey('doc');
        static::assertSame(['payload' => ['number' => PHP_INT_MAX]], $row->observe(['payload']));
        $fixture->flush();
    }

    public function test_postgres_timestamp_keys_keep_the_same_membership_across_session_offsets(): void
    {
        $fixture = $this->fixture('postgres', columnTypes: ['id' => 'timestamptz(3)']);
        $connection = $fixture->connection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement("SET LOCAL TIME ZONE 'Europe/Paris'");
            $fixture->seed(['id' => '2026-05-25T10:34:56.123Z']);
            $rows = $fixture->rows;
            $alias = '2026-05-25T05:34:56.123-05:00';
            $rows->expectSameRowKeys([$alias]);
            $fixture->flush();
            static::assertSame(['id' => '2026-05-25T10:34:56.123000+00:00'], $rows->withKey($alias)->get([]));
        } finally {
            $connection->rollBack();
        }
    }

    public function test_postgres_timestamp_reads_use_the_session_offset(): void
    {
        $fixture = $this->fixture('postgres', columnTypes: ['expires_on' => 'timestamptz(3)']);
        $connection = $fixture->connection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement("SET LOCAL TIME ZONE 'Europe/Paris'");
            $fixture->seed(['id' => 'doc', 'expires_on' => '2026-05-25T10:34:56.1235Z']);
            static::assertSame(
                ['expires_on' => '2026-05-25T12:34:56.124000+02:00'],
                $fixture->rows->withKey('doc')->observe(['expires_on']),
            );
            $fixture->rows->withKey('doc')->expectColumnValue('expires_on', '2026-05-25T10:34:56.124Z');
            $fixture->flush();
        } finally {
            $connection->rollBack();
        }
    }

    public function test_array_upserts_preserve_values_and_reject_stale_array_expectations(): void
    {
        $fixture = $this->fixture('postgres', columnTypes: ['tags' => 'text[]']);
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
