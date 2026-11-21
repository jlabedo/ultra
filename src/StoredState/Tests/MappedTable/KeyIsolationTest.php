<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class KeyIsolationTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function singleColumnAddresses(): iterable
    {
        foreach (self::backends() as $backendName => [$backend]) {
            foreach (['scalar', 'Stringable', 'structured Stringable', 'associative Stringable'] as $address) {
                yield $backendName . ' ' . $address => [$backend, $address];
            }
        }
    }

    #[DataProvider('singleColumnAddresses')]
    public function test_the_default_single_column_key_accepts_equivalent_addresses(
        string $backend,
        string $address,
    ): void {
        $fixture = $this->fixture($backend);
        $rows = $fixture->rows;
        $key = match ($address) {
            'scalar' => 'row',
            'Stringable' => self::stringable('row'),
            'structured Stringable' => new MappedKey(['id' => self::stringable('row')]),
            'associative Stringable' => ['id' => self::stringable('row')],
        };
        $rows->withKey($key)->insert()->setColumn('status', 'pending');
        $rows->withKey('row')->setColumn('status', 'saved');
        static::assertSame(['status' => 'saved'], $rows->withKey($key)->get(['status']));
        $rows->expectSameRowKeys([$key]);
        $fixture->flush($rows);
        $rows->clear();
        static::assertSame(['status' => 'saved'], $rows->withKey($key)->get(['status']));
        static::assertSame(['id' => 'row'], $rows->rows()[0]->key->values);
        $rows->expectSameRowKeys([$key]);
        $fixture->flush($rows);
    }

    #[DataProvider('backends')]
    public function test_composite_keys_keep_colliding_item_ids_independent(string $backend): void
    {
        [$fixture, $rows] = $this->ownedRows($backend);
        $one = ['owner' => self::stringable('one'), 'tenant' => 'prod'];
        $two = ['owner' => 'two', 'tenant' => 'prod'];
        $dev = ['owner' => 'one', 'tenant' => 'dev'];
        foreach ([$one, $two, $dev] as $index => $owner) {
            $item = [...$owner, 'id' => self::stringable('same')];
            $rows
                ->withKey($item)
                ->insert()
                ->setColumn('status', 'value-' . $index);
        }
        $fixture->flush($rows);

        $key = new MappedKey([...$one, 'id' => self::stringable('same')]);
        static::assertSame(['id' => 'same', 'owner' => 'one', 'tenant' => 'prod'], $key->values);
        static::assertTrue($key->matches($one));
        static::assertEquals($key, new MappedKey($one)->mergedWith(['id' => 'same']));
        static::assertSame($key->values, $rows->withKey($key->values)->get([]));
        static::assertEquals([$key], $rows->observeKeysForOwner($one));
        $rows->withKey($key)->setColumn('status', 'updated');
        $fixture->flush($rows);

        static::assertSame(['status' => 'updated'], $rows->withKey($key)->get(['status']));
        $rows->withKey($key)->delete();
        $fixture->flush($rows);
        static::assertNull($rows->withKey($key)->get());
        static::assertSame(['status' => 'value-1'], $rows->withKey([...$two, 'id' => 'same'])->get(['status']));
        static::assertSame(['status' => 'value-2'], $rows->withKey([...$dev, 'id' => 'same'])->get(['status']));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'scalar for a composite key' => ['scalar'];
        yield 'missing key column' => ['missing'];
        yield 'positional key array' => ['positional'];
        yield 'associative key outside the collection' => ['outside'];
    }

    #[DataProvider('invalidAddresses')]
    public function test_addresses_must_match_the_declared_key_or_owner(string $case): void
    {
        [, $rows] = $this->ownedRows('memory');
        $owner = ['tenant' => 'prod', 'owner' => 'one'];
        $outside = ['tenant' => 'prod', 'owner' => 'two', 'id' => 'same'];
        $this->expectException(\InvalidArgumentException::class);
        match ($case) {
            'scalar' => $rows->withKey('same'),
            'missing' => $rows->withKey(new MappedKey(['id' => 'same'])),
            'positional' => $rows->withKey(['prod', 'one', 'same']),
            'outside' => $rows->expectSameRowKeysForOwner($owner, [$outside]),
        };
    }

    public function test_the_in_memory_storage_api_preserves_scopes_and_unscoped_upserts(): void
    {
        $database = new InMemoryRowDatabase();
        $database->writeRow('rows', 'id', ['id' => 'same', 'owner' => 'one', 'status' => 'first'], ['owner' => 'one']);
        $database->writeRow('rows', 'id', ['id' => 'same', 'owner' => 'two', 'status' => 'second'], ['owner' => 'two']);
        $database->writeRow('rows', 'id', ['id' => 'other', 'owner' => 'one'], ['owner' => 'one']);
        $database->writeRow(
            'rows',
            'id',
            ['id' => 'same', 'owner' => 'one', 'status' => 'updated'],
            ['owner' => 'one'],
        );
        static::assertSame('updated', $database->row('rows', 'id', 'same', scopeColumns: ['owner' => 'one'])['status']);
        static::assertSame('second', $database->row('rows', 'id', 'same', scopeColumns: ['owner' => 'two'])['status']);
        static::assertSame(['other', 'same'], array_keys($database->rows('rows', 'id', scopeColumns: [
            'owner' => 'one',
        ])));
        $database->deleteRow('rows', 'id', 'same', ['owner' => 'one']);
        static::assertFalse($database->row('rows', 'id', 'same', scopeColumns: ['owner' => 'one']));
        static::assertSame('second', $database->row('rows', 'id', 'same', scopeColumns: ['owner' => 'two'])['status']);
        $database->writeRow('unscoped', 'id', ['id' => 'same', 'status' => 'first']);
        $database->writeRow('unscoped', 'id', ['id' => 'same', 'status' => 'last']);
        static::assertSame('last', $database->row('unscoped', 'id', 'same')['status']);
        static::assertCount(1, $database->rows('unscoped', 'id'));
    }

    private static function stringable(string $value): \Stringable
    {
        return new readonly class($value) implements \Stringable {
            public function __construct(
                private string $value,
            ) {}

            public function __toString(): string
            {
                return $this->value;
            }
        };
    }
}
