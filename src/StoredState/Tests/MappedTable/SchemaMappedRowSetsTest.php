<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\MappedTable\Symfony\SchemaMappedRowSet;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class SchemaMappedRowSetsTest extends TestCase
{
    public function test_dumped_container_uses_physical_names_the_inferred_key_and_the_requested_columns(): void
    {
        $schema = new SchemaDefinition([
            new TableDefinition(
                'test.entries',
                'physical_entries',
                [
                    new ColumnDefinition('id', 'entry_id', ColumnType::Int32),
                    new ColumnDefinition('name', 'entry_name', ColumnType::Text),
                    new ColumnDefinition('note', 'entry_note', 'varchar(40)', nullable: true),
                    new ColumnDefinition('internal', type: 'jsonb'),
                ],
                primaryKey: ['id'],
            ),
        ]);
        $container = self::container(
            $schema,
            new SchemaMappedRowSet('test.entries', columns: ['name', 'note'], representations: ['note' => 'string']),
        );
        $container->setAlias('test.public_entries', 'test.entries')->setPublic(true);
        $container->compile();

        $class = 'SchemaMappedRowSetsContainer' . bin2hex(random_bytes(6));
        $php = new PhpDumper($container)->dump(['class' => $class]);
        self::assertStringNotContainsString(SchemaDefinition::class, $php);
        self::assertStringNotContainsString(SchemaMappedRowSet::class, $php);
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
        $entry->insert()->setColumns(['entry_name' => 'Workshop', 'entry_note' => null]);
        self::assertSame(['entry_id' => 7], $entry->get([]));
        self::assertSame(['entry_id' => 7, 'entry_name' => 'Workshop', 'entry_note' => null], $entry->observe());
    }

    public function test_native_types_require_an_explicit_representation_at_compilation(): void
    {
        $schema = new SchemaDefinition([
            new TableDefinition(
                'test.entries',
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                    new ColumnDefinition('dates', type: 'date[]'),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Select a PHP representation for column "dates" on schema table "test.entries".');
        self::container($schema, new SchemaMappedRowSet('test.entries'))->compile();
    }

    public function test_the_owner_foreign_key_requires_its_parent_row_set_to_be_selected(): void
    {
        $schema = new SchemaDefinition([
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

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Owner definition "test.parents" of table "test.children" is missing.');
        self::container($schema, new SchemaMappedRowSet('test.children', owner: 'parent_reference'))->compile();
    }

    private static function container(SchemaDefinition $schema, SchemaMappedRowSet ...$selections): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new MappedTableExtension(schema: $schema, schemaRowSets: $selections)->build($container);
        $container
            ->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)
            ->setSynthetic(true)
            ->setPublic(true);

        return $container;
    }
}
