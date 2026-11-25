<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedRowSetCompiler;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SqlType;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class SchemaRowSetCompilationTest extends TestCase
{
    public function test_manually_compiled_row_set_can_create_independent_buffers(): void
    {
        $adapter = new InMemoryMappedTableAdapter(new InMemoryRowDatabase());
        $factory = $adapter->compileRowSet(MappedRowSetsSchema::entries(), 'test.entries', columns: ['name']);

        $first = $factory()->withKey(7);
        $first->insert()->setColumn('entry_name', 'Workshop');
        self::assertSame(['entry_name' => 'Workshop'], $first->get(['entry_name']));
        self::assertNull($factory()->withKey(7)->get());
    }

    public function test_dumped_container_uses_physical_names_the_inferred_key_and_the_requested_columns(): void
    {
        $container = self::container(['test.entries' => ['columns' => ['name', 'note']]]);
        $container->setAlias('test.public_entries', 'test.entries')->setPublic(true);
        $container->compile();

        $class = 'CompiledSchemaRowSetsContainer' . bin2hex(random_bytes(6));
        $php = new PhpDumper($container)->dump(['class' => $class]);
        self::assertStringNotContainsString(SchemaDefinition::class, $php);
        self::assertStringNotContainsString(MappedRowSetCompiler::class, $php);
        $file = tempnam(sys_get_temp_dir(), 'schema-mapped-table-');
        assert(is_string($file));
        try {
            file_put_contents($file, $php);
            require $file;
        } finally {
            unlink($file);
        }
        $compiled = new $class();
        $compiled->set(MappedTableAdapter::class, new InMemoryMappedTableAdapter(new InMemoryRowDatabase()));

        $entry = $compiled->get('test.public_entries')->withKey(['entry_id' => 7]);
        $entry->insert()->setColumns(['entry_name' => 'Workshop', 'entry_note' => '2026-05-25']);
        self::assertSame(['entry_id' => 7], $entry->get([]));
        self::assertSame(
            ['entry_id' => 7, 'entry_name' => 'Workshop', 'entry_note' => '2026-05-25'],
            $entry->observe(),
        );
    }

    public function test_a_row_set_cannot_expose_a_column_of_an_unknown_sql_type(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Unsupported SQL type "hstore" for column "internal" on mapped schema table "test.entries".',
        );
        self::container(['test.entries'])->compile();
    }

    public function test_a_temporal_column_cannot_be_a_mapped_key(): void
    {
        $schema = new SchemaDefinition([new TableDefinition(
            'entries',
            columns: [
                new ColumnDefinition('entered_at', type: 'timestamptz'),
            ],
            primaryKey: ['entered_at'],
        )]);
        $adapter = new InMemoryMappedTableAdapter(new InMemoryRowDatabase());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key column "entered_at" must be mapped to a non-null int or string key.');
        $adapter->compileRowSet($schema, 'entries');
    }

    public function test_the_owner_foreign_key_requires_its_parent_row_set_to_be_selected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Owner definition "test.parents" of table "test.children" is missing.');
        self::container(['test.children' => ['owner' => 'parent_reference']])->compile();
    }

    /** @param array<array-key, string|array{columns?: list<string>, owner?: string}> $selections */
    private static function container(array $selections): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new SchemaExtension()->build($container);
        $container->register(MappedRowSetsSchema::class)->setAutoconfigured(true);
        new MappedTableExtension(rowSets: $selections)->build($container);
        $container
            ->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)
            ->setSynthetic(true)
            ->setPublic(true);

        return $container;
    }
}

final class MappedRowSetsSchema
{
    #[DefineSchema]
    public static function entries(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                'test.entries',
                'physical_entries',
                [
                    new ColumnDefinition('id', 'entry_id', ColumnType::Int32),
                    new ColumnDefinition('name', 'entry_name', ColumnType::Text),
                    new ColumnDefinition('note', 'entry_note', SqlType::fromSql('date'), nullable: true),
                    new ColumnDefinition('internal', type: SqlType::fromSql('hstore')),
                ],
                primaryKey: ['id'],
            ),
        ]);
    }

    #[DefineSchema]
    public static function ownership(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                'test.parents',
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                ],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                'test.children',
                columns: [
                    new ColumnDefinition('parent', type: ColumnType::Int32),
                    new ColumnDefinition('id', type: ColumnType::Int32),
                ],
                primaryKey: ['parent', 'id'],
                foreignKeys: [
                    'parent_reference' => new ForeignKeyDefinition('test.parents', ['parent' => 'id']),
                ],
            ),
        ]);
    }
}
