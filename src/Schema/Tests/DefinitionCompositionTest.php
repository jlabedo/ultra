<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;
use Ultra\Schema\TableDefinition;

/** @internal */
#[CoversClass(SchemaDefinition::class)]
#[CoversClass(TableDefinition::class)]
#[CoversClass(ColumnDefinition::class)]
#[CoversClass(IndexDefinition::class)]
final class DefinitionCompositionTest extends TestCase
{
    #[DataProvider('nameOrders')]
    public function test_fragments_combine_by_identity_and_keep_explicit_names(bool $explicitFirst): void
    {
        $implicit = new SchemaDefinition([new TableDefinition(
            'users',
            columns: [
                new ColumnDefinition('id', type: 'uuid'),
                new ColumnDefinition(
                    'email',
                    type: 'text',
                    nullable: true,
                    defaultExpression: "'unknown'",
                    constraints: ['UNIQUE'],
                ),
                new ColumnDefinition('status', type: 'text'),
            ],
            indexes: [new IndexDefinition('lookup', columns: ['email']), new IndexDefinition('ids', columns: ['id'])],
        )]);
        $explicit = new SchemaDefinition([new TableDefinition(
            'users',
            'app_users',
            [
                new ColumnDefinition(
                    'email',
                    'address',
                    'text',
                    nullable: true,
                    defaultExpression: "'unknown'",
                    constraints: ['UNIQUE'],
                ),
            ],
            [new IndexDefinition('lookup', 'email_lookup', ['email'])],
        )]);
        $implicit = $implicit->mergedWith($implicit);
        $explicit = $explicit->mergedWith($explicit);
        $merged = $explicitFirst ? $explicit->mergedWith($implicit) : SchemaDefinition::merge($implicit, $explicit);
        $table = $merged->tablesById()['users'];

        static::assertCount(1, $merged->tables);
        static::assertIsList($merged->tables);
        static::assertIsList($table->columns);
        static::assertIsList($table->indexes);
        static::assertSame('app_users', $table->name);
        static::assertTrue($table->hasExplicitName);
        static::assertSame(
            $explicitFirst ? ['email', 'id', 'status'] : ['id', 'email', 'status'],
            array_keys($table->columnsById()),
        );
        static::assertSame(['lookup', 'ids'], array_keys($table->indexesById()));
        static::assertEquals($explicit->tables[0]->columns[0], $table->columnsById()['email']);
        static::assertEquals($explicit->tables[0]->indexes[0], $table->indexesById()['lookup']);
        static::assertSame('id', $table->columnsById()['id']->name);
        static::assertSame('ids', $table->indexesById()['ids']->name);
        static::assertSame([], new SchemaReconciler()->reconcile($merged, $merged));
    }

    /** @return iterable<string, array{bool}> */
    public static function nameOrders(): iterable
    {
        yield 'implicit then explicit' => [false];
        yield 'explicit then implicit' => [true];
    }

    #[DataProvider('duplicates')]
    public function test_duplicate_identities_and_names_are_rejected(string $kind, string $key): void
    {
        $id = 'id' === $key ? 'same' : 'other';
        $name = 'name' === $key ? 'same' : 'other';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Duplicate ' . $kind . ' ' . $key . ' "same"' . ('table' === $kind ? '.' : ' in table "users".'),
        );
        match ($kind) {
            'table' => new SchemaDefinition([new TableDefinition('same', 'same'), new TableDefinition($id, $name)]),
            'column' => new TableDefinition('t', 'users', [
                new ColumnDefinition('same', 'same', 'text'),
                new ColumnDefinition($id, $name, 'text'),
            ]),
            'index' => new TableDefinition('t', 'users', indexes: [
                new IndexDefinition('same', 'same'),
                new IndexDefinition($id, $name),
            ]),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function duplicates(): iterable
    {
        foreach (['table', 'column', 'index'] as $kind) {
            foreach (['id', 'name'] as $key) {
                yield $kind . ' ' . $key => [$kind, $key];
            }
        }
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('conflicts')]
    public function test_conflicting_fragments_cannot_silently_replace_definitions(string $kind, array $changes): void
    {
        $left = match ($kind) {
            'table' => new TableDefinition('t', 'users'),
            'column' => new TableDefinition('t', columns: [new ColumnDefinition('c', 'value', 'text')]),
            'index' => new TableDefinition('t', indexes: [new IndexDefinition('i', 'lookup', ['value'])]),
        };
        $right = match ($kind) {
            'table' => new TableDefinition('t', ...$changes),
            'column' => new TableDefinition('t', columns: [new ColumnDefinition(
                'c',
                ...$changes + ['name' => 'value', 'type' => 'text'],
            )]),
            'index' => new TableDefinition('t', indexes: [new IndexDefinition(
                'i',
                ...$changes + ['name' => 'lookup', 'columns' => ['value']],
            )]),
        };
        $nameConflict = isset($changes['name']);
        $id = match ($kind) {
            'table' => 't',
            'column' => 'c',
            'index' => 'i',
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            $nameConflict ? sprintf('Conflicting %s name for "%s": "%s" and "other".', $kind, $id, match ($kind) {
                    'table' => 'users',
                    'column' => 'value',
                    'index' => 'lookup',
                }) : sprintf('Conflicting %s definition for "%s" in table "t".', $kind, $id),
        );
        SchemaDefinition::merge(new SchemaDefinition([$left]), new SchemaDefinition([$right]));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function conflicts(): iterable
    {
        foreach (['table', 'column', 'index'] as $kind) {
            yield $kind . ' name' => [$kind, ['name' => 'other']];
        }
        foreach ([
            'type' => 'uuid',
            'nullable' => true,
            'defaultExpression' => 'now()',
            'constraints' => ['UNIQUE'],
        ] as $key => $value) {
            yield 'column ' . $key => ['column', [$key => $value]];
        }
        foreach ([
            'columns' => ['other'],
            'unique' => true,
            'rawSql' => 'CREATE INDEX lookup ON users(value)',
        ] as $key => $value) {
            yield 'index ' . $key => ['index', [$key => $value]];
        }
    }

    public function test_columns_require_an_explicit_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Column "value" must define a type.');
        new ColumnDefinition('value');
    }
}
