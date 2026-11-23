<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;

/** @internal */
#[CoversNamespace('Ultra\Schema')]
final class SymfonySchemaDiscoveryTest extends TestCase
{
    public function test_fragments_are_discovered_once_and_cross_fragment_references_are_resolved_before_dumping(): void
    {
        ParentSchema::$calls = 0;
        $container = new ContainerBuilder();
        new SchemaExtension()->build($container);
        $container->register(ChildSchema::class)->setAutoconfigured(true);
        $container->register(ParentSchema::class)->setAutoconfigured(true);
        $container->register('test.parent_schema', ParentSchema::class)->setAutoconfigured(true);
        $consumer = new class implements CompilerPassInterface {
            public SchemaDefinition $schema;

            public function process(ContainerBuilder $container): void
            {
                $this->schema = SchemaExtension::compiledSchema($container);
            }
        };
        $container->addCompilerPass($consumer, priority: -1);
        $container->compile();

        self::assertSame(1, ParentSchema::$calls);
        $tables = $consumer->schema->tablesById();
        self::assertSame('parents', $tables['children']->foreignKeys['parent_reference']->table);
        self::assertSame(['id'], $tables['parents']->primaryKey);
        $php = new PhpDumper($container)->dump();
        self::assertStringNotContainsString(ParentSchema::class, $php);
        self::assertStringNotContainsString(ChildSchema::class, $php);
        self::assertStringNotContainsString(SchemaDefinition::class, $php);
    }

    public function test_schema_discovery_rejects_instance_methods_during_compilation(): void
    {
        $container = new ContainerBuilder();
        new SchemaExtension()->build($container);
        $container->register(BadSchema::class)->setAutoconfigured(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(BadSchema::class . '::definition');
        $container->compile();
    }

    public function test_schema_discovery_rejects_references_to_tables_missing_from_the_assembled_schema(): void
    {
        $container = new ContainerBuilder();
        new SchemaExtension()->build($container);
        $container->register(ChildSchema::class)->setAutoconfigured(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Foreign key "parent_reference" of table "children"');
        $container->compile();
    }
}

final class ParentSchema
{
    public static int $calls = 0;

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        ++self::$calls;

        return new SchemaDefinition([
            new TableDefinition(
                'parents',
                columns: [new ColumnDefinition('id', type: ColumnType::Int32)],
                primaryKey: ['id'],
            ),
        ]);
    }
}

final class ChildSchema
{
    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new TableDefinition(
                'children',
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Int32),
                    new ColumnDefinition('parent_id', type: ColumnType::Int32),
                ],
                primaryKey: ['id'],
                foreignKeys: ['parent_reference' => new ForeignKeyDefinition('parents', ['parent_id' => 'id'])],
            ),
        ]);
    }
}

final class BadSchema
{
    #[DefineSchema]
    public function definition(): SchemaDefinition
    {
        return new SchemaDefinition([]);
    }
}
