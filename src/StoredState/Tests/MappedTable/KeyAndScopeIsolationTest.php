<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\MappedTable\InMemoryMappedRowSetAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\PostgresMappedRowSetAdapter;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class KeyAndScopeIsolationTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_the_default_single_column_key_accepts_scalar_addresses(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $adapter = 'memory' === $backend
            ? new InMemoryMappedRowSetAdapter($fixture->storage, $fixture->tableName)
            : new PostgresMappedRowSetAdapter($fixture->connection(), $fixture->tableName);
        $rows = new MappedRowSet($adapter);
        $rows->withKey('row')->insert()->setColumn('status', 'saved');
        $fixture->flush($rows);
        $rows->clear();
        static::assertSame(['status' => 'saved'], $rows->row('row', ['status']));
        static::assertSame(['id' => 'row'], $rows->rows()[0]->key->values);
    }

    #[DataProvider('backends')]
    public function test_composite_keys_and_scoped_shortcuts_keep_colliding_ids_independent(string $backend): void
    {
        $fixture = $this->fixture($backend, ['owner', 'env', 'id']);
        $one = $fixture->view(new MappedKey(['owner' => 'one', 'env' => 'prod']));
        $two = $fixture->view(new MappedKey(['owner' => 'two', 'env' => 'prod']));
        $dev = $fixture->view(new MappedKey(['owner' => 'one', 'env' => 'dev']));
        foreach ([$one, $two, $dev] as $index => $rows) {
            $rows
                ->withKey('same')
                ->insert()
                ->setColumn('status', 'value-' . $index);
            $fixture->flush($rows);
            $rows->clear();
        }
        $key = new MappedKey(['id' => 'same', 'env' => 'prod', 'owner' => 'one']);
        static::assertSame(['status' => 'value-0'], $fixture->rows->row($key, ['status']));
        static::assertSame($key->values, $one->rows(['status'])[0]->key->values);
        static::assertCount(1, $one->rows());
        $one->withKey('same')->setColumn('status', 'updated');
        $fixture->flush($one);
        $one->clear();
        static::assertSame(['status' => 'updated'], $one->row('same', ['status']));
        static::assertSame(['status' => 'value-1'], $two->row('same', ['status']));
        static::assertSame(['status' => 'value-2'], $dev->row('same', ['status']));
        $one->withKey('same')->delete();
        $fixture->flush($one);
        $one->clear();
        static::assertFalse($one->row('same'));
        static::assertNotFalse($two->row('same'));
        static::assertNotFalse($dev->row('same'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'scalar for multiple unbound columns' => ['scalar'];
        yield 'missing key column' => ['missing'];
        yield 'key outside scope' => ['outside'];
    }

    #[DataProvider('invalidAddresses')]
    public function test_keys_must_match_the_declared_columns_and_scope(string $case): void
    {
        $fixture = $this->fixture(keyColumns: ['owner', 'id']);
        $rows = $fixture->view(new MappedKey(['owner' => 'one']));
        $this->expectException(\InvalidArgumentException::class);
        match ($case) {
            'scalar' => $fixture->rows->row('id'),
            'missing' => $rows->row(new MappedKey(['id' => 'id'])),
            'outside' => $rows->row(new MappedKey(['owner' => 'two', 'id' => 'id'])),
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
}
