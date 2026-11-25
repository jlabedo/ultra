<?php

declare(strict_types=1);

namespace Ultra\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\Migration\MigrationGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;
use Ultra\SymfonyBundle\UltraBundle;

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

    public function test_a_service_with_a_static_fragment_can_be_autowired_and_injected(): void
    {
        ParentSchema::$calls = 0;
        $container = new ContainerBuilder();
        new SchemaExtension()->build($container);
        $container->register(\stdClass::class, \stdClass::class);
        $container->register(SchemaDeclaringService::class)->setAutoconfigured(true)->setAutowired(true);
        $container->register(SchemaServiceConsumer::class)->setAutowired(true)->setPublic(true);
        $container->compile();

        self::assertSame(1, ParentSchema::$calls);
        $consumer = $container->get(SchemaServiceConsumer::class);
        self::assertInstanceOf(SchemaServiceConsumer::class, $consumer);
        self::assertInstanceOf(\stdClass::class, $consumer->service->dependency);
    }

    /** @param array{schema?: array{migrations_directory: string}} $config */
    #[DataProvider('migrationDirectories')]
    public function test_symfony_command_generates_from_compiled_fragments_and_the_recorded_history(
        array $config,
        string $projectRelativeHistory,
    ): void {
        $suffix = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/ultra_schema_symfony_' . $suffix;
        mkdir($directory);

        try {
            $history = $directory . '/' . $projectRelativeHistory;
            $previous = new SchemaDefinition([MigrationTargetSchema::table('items_v1')]);
            new MigrationGenerator($history)->initialize($previous);
            MigrationTargetSchema::$calls = 0;

            $builder = new ContainerBuilder();
            $builder->setParameter('kernel.project_dir', $directory);
            $bundle = new UltraBundle([new SchemaExtension()]);
            $bundle->build($builder);
            $builder->registerExtension($bundle->getContainerExtension());
            $builder->loadFromExtension('ultra', $config);
            $builder->addCompilerPass(new AddConsoleCommandPass());
            $builder->setParameter('schema_test_token', 'unexpected substitution');
            $builder->register(MigrationTargetSchema::class)->setAutoconfigured(true);
            $builder->compile();

            $tester = $this->console($builder, $directory, $suffix);
            $arguments = [
                'command' => 'ultra:schema:generate',
                'name' => 'rename-items',
            ];
            self::assertSame(0, $tester->run($arguments), $tester->getDisplay());

            $path = $history . '/000001-rename-items';
            $sql = file_get_contents($path . '/migration.sql');
            self::assertStringContainsString('ALTER TABLE "items_v1" RENAME TO "items_v2";', $sql);
            self::assertStringNotContainsString('SET DEFAULT', $sql);
            self::assertStringContainsString(
                '"previous": "000000-baseline"',
                file_get_contents($path . '/snapshot.json'),
            );
            self::assertSame(0, $tester->run($arguments), $tester->getDisplay());
            self::assertStringContainsString('No schema changes.', $tester->getDisplay());
            self::assertSame(1, MigrationTargetSchema::$calls);
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    /** @return iterable<string, array{array{schema?: array{migrations_directory: string}}, string}> */
    public static function migrationDirectories(): iterable
    {
        yield 'bundle default' => [[], 'migrations/schema'];
        yield 'configured path' => [['schema' => ['migrations_directory' => 'database/ultra']], 'database/ultra'];
    }

    public function test_generate_invokes_only_the_discovered_service_methods(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $directory = sys_get_temp_dir() . '/ultra_schema_service_methods_' . $suffix;
        mkdir($directory);

        try {
            $builder = new ContainerBuilder();
            $builder->setParameter('ultra.schema.migrations_directory', $directory . '/migrations');
            new SchemaExtension()->build($builder);
            $builder->addCompilerPass(new AddConsoleCommandPass());
            $builder
                ->register('test.schema', ConfiguredSchema::class)
                ->setArguments(['fixture'])
                ->setAutoconfigured(true)
                ->addTag('ultra.schema.fragment', ['method' => 'parentTable'])
                ->addTag('ultra.schema.fragment', ['method' => 'relatedTable'])
                ->addTag('ultra.schema.fragment', ['method' => 'relatedTable']);
            ConfiguredSchema::$calls = [];
            ConfiguredSchema::$instances = 0;
            $builder->compile();

            $tester = $this->console($builder, $directory, $suffix);
            self::assertSame(0, $tester->run(['command' => 'ultra:schema:generate', '--help' => true]));
            self::assertSame(0, ConfiguredSchema::$instances);
            self::assertSame([], ConfiguredSchema::$calls);

            $arguments = ['command' => 'ultra:schema:generate', 'name' => 'create-tables'];
            self::assertSame(0, $tester->run($arguments), $tester->getDisplay());
            $sql = file_get_contents($directory . '/migrations/000001-create-tables/migration.sql');
            self::assertStringContainsString('CREATE TABLE "parents"', $sql);
            self::assertStringContainsString('CREATE TABLE "fixture_messages"', $sql);
            self::assertStringContainsString('CREATE TABLE "fixture_related"', $sql);
            self::assertStringContainsString('REFERENCES "parents" ("id")', $sql);
            self::assertSame(1, ConfiguredSchema::$instances);
            self::assertEqualsCanonicalizing(['messages', 'relatedTable'], ConfiguredSchema::$calls);
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    private function console(ContainerBuilder $builder, string $directory, string $suffix): ApplicationTester
    {
        $class = 'SchemaMigrationContainer' . $suffix;
        $file = $directory . '/container.php';
        file_put_contents($file, new PhpDumper($builder)->dump(['class' => $class]));
        require $file;
        $loader = new $class()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader($loader);

        return new ApplicationTester($application);
    }

    public function test_schema_discovery_rejects_instance_methods_with_parameters_during_compilation(): void
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

final readonly class SchemaDeclaringService
{
    public function __construct(
        public \stdClass $dependency,
    ) {}

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return ParentSchema::definition();
    }
}

final readonly class SchemaServiceConsumer
{
    public function __construct(
        public SchemaDeclaringService $service,
    ) {}
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
    public function definition(string $name): SchemaDefinition
    {
        return new SchemaDefinition([]);
    }
}

final class MigrationTargetSchema
{
    public static int $calls = 0;

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        ++self::$calls;

        return new SchemaDefinition([self::table('items_v2')]);
    }

    public static function table(string $name): TableDefinition
    {
        return new TableDefinition('items', $name, [
            new ColumnDefinition('label', type: ColumnType::Text, defaultExpression: "'%schema_test_token%'"),
        ]);
    }
}

final class ConfiguredSchema
{
    public static int $instances = 0;

    /** @var list<string> */
    public static array $calls = [];

    public function __construct(
        private readonly string $prefix,
    ) {
        ++self::$instances;
    }

    public static function parentTable(): SchemaDefinition
    {
        return ParentSchema::definition();
    }

    #[DefineSchema]
    public function messages(): SchemaDefinition
    {
        self::$calls[] = __FUNCTION__;

        return new SchemaDefinition([
            new TableDefinition(
                'test_messages',
                $this->prefix . '_messages',
                [new ColumnDefinition('parent_id', type: ColumnType::Int32)],
                foreignKeys: ['message_parent' => new ForeignKeyDefinition('parents', ['parent_id' => 'id'])],
            ),
        ]);
    }

    public function relatedTable(): SchemaDefinition
    {
        self::$calls[] = __FUNCTION__;

        return new SchemaDefinition([
            new TableDefinition('test_related', $this->prefix . '_related', [
                new ColumnDefinition('id', type: ColumnType::Int32),
            ]),
        ]);
    }

    public function untagged(): SchemaDefinition
    {
        throw new \LogicException('An untagged method must not be called.');
    }
}
