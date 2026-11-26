<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\Attribute\DefineQueryGuard;
use Ultra\MappedTable\Guard\QueryGuardRules;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuard;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Schema\SchemaRowSet;
use Ultra\MappedTable\Storage\PostgresRowSetSql;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ForeignKeyDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SqlType;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkExtension;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class DefinedRowSetsTest extends RowSetTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function configuredAdapters(): iterable
    {
        yield 'memory adapter resolved at runtime' => ['memory', true];
        yield 'Postgres adapter resolved at runtime' => ['postgres', true];
        yield 'Postgres adapter configured as a service' => ['postgres', false];
    }

    #[DataProvider('configuredAdapters')]
    public function test_definitions_compile_to_shared_row_sets_using_the_application_adapter(
        string $backend,
        bool $syntheticAdapter,
    ): void {
        $fixture = $this->fixture($backend);
        $storage = $fixture->storage;
        if ($storage instanceof InMemoryRowDatabase) {
            $adapter = new InMemoryMappedTableAdapter($storage);
        } else {
            $storage->executeStatement('CREATE TEMP TABLE mapped_definition_orders (id TEXT PRIMARY KEY)');
            $storage->executeStatement(
                'CREATE TEMP TABLE mapped_definition_lines (owner TEXT REFERENCES mapped_definition_orders (id), id TEXT, tags TEXT[], aliases TEXT[], PRIMARY KEY (owner, id))',
            );
            $adapter = new PostgresMappedTableAdapter($storage);
        }
        CompileTimeRowsSchema::$definitions = 0;

        try {
            $container = self::container([
                CompileTimeRowsSchema::ORDERS,
                CompileTimeRowsSchema::LINES => ['owner' => 'owner'],
            ], [
                new QueryGuardDefinition('lines', [
                    new QueryGuardDependency(CompileTimeRowsSchema::LINES, 'owner', ['tags']),
                    new QueryGuardDependency(CompileTimeRowsSchema::LINES, 'owner', ['aliases']),
                ]),
            ]);
            new UnitOfWorkExtension()->build($container);
            if ($syntheticAdapter) {
                $container->register(MappedTableAdapter::class, $adapter::class)->setSynthetic(true)->setPublic(true);
            } else {
                $container->register(Connection::class)->setSynthetic(true)->setPublic(true);
                $container->setParameter('test.adapter_class', PostgresMappedTableAdapter::class);
                $container
                    ->register(MappedTableAdapter::class, '%test.adapter_class%')
                    ->setArguments([new Reference(Connection::class)]);
            }
            $container->register(DirectLineWriter::class)->setAutowired(true)->setPublic(true);
            $container->setAlias('test.lines', CompileTimeRowsSchema::LINES)->setPublic(true);
            $container->setAlias('test.orders', CompileTimeRowsSchema::ORDERS)->setPublic(true);
            $container->setAlias('test.unit_of_work', UnitOfWork::class)->setPublic(true);
            $container->setAlias('test.coordination', MappedTableUnitOfWork::class)->setPublic(true);
            $container->compile();
            if ('postgres' === $backend) {
                $lineService = $container->findDefinition('test.lines');
                static::assertSame('createCompiled', $lineService->getFactory()[1]);
                $sql = $lineService->getArgument(1);
                static::assertInstanceOf(Definition::class, $sql);
                static::assertSame(PostgresRowSetSql::class, $sql->getClass());
                static::assertSame(' WHERE "owner" = ?', $sql->getArgument(7));
                static::assertSame('"aliases" = ?', $sql->getArgument(1)['aliases']['assign']);
                static::assertSame(2, count($lineService->getArguments()));
            }
            $coordination = $container->findDefinition('test.coordination');
            static::assertSame(MappedTableAdapter::class, (string) $coordination->getArgument(1));
            static::assertSame(
                [CompileTimeRowsSchema::ORDERS => true, CompileTimeRowsSchema::LINES => false],
                $coordination->getArgument(2),
            );
            $guardRules = $coordination->getArgument(3);
            static::assertInstanceOf(Definition::class, $guardRules);
            static::assertSame(QueryGuardRules::class, $guardRules->getClass());
            static::assertSame(['lines' => [CompileTimeRowsSchema::LINES => [0]]], $guardRules->getArgument(1));
            static::assertSame([CompileTimeRowsSchema::LINES => [0]], $guardRules->getArgument(2));
            static::assertSame(
                ['owner' => true, 'tags' => true, 'aliases' => true],
                $guardRules->getArgument(0)[0]->getArgument(4),
            );

            $dumpedClass = 'MappedTableContainer' . bin2hex(random_bytes(8));
            $file = tempnam(sys_get_temp_dir(), 'mapped-table-');
            assert(is_string($file));
            try {
                file_put_contents($file, new PhpDumper($container)->dump(['class' => $dumpedClass]));
                require $file;
            } finally {
                unlink($file);
            }

            $compiled = new $dumpedClass();
            if ($syntheticAdapter) {
                $compiled->set(MappedTableAdapter::class, $adapter);
            } else {
                $compiled->set(Connection::class, $storage);
            }
            $lines = $compiled->get('test.lines');
            $writer = $compiled->get(DirectLineWriter::class);
            static::assertSame($lines, $writer->lines);
            $queries = 0;
            $writer->guard->observe('one', static function () use (&$queries): int {
                ++$queries;

                return 0;
            });
            $line = new MappedKey(['owner' => 'one', 'id' => 'line']);
            $writer
                ->lines
                ->withKey($line)
                ->insert()
                ->setColumns(['tags' => ['one', 'two'], 'aliases' => ['third']]);
            $compiled->get('test.orders')->withKey('one')->insert();
            static::assertSame(
                ['tags' => ['one', 'two'], 'aliases' => ['third']],
                $lines->rowsForOwner(new MappedKey(['owner' => 'one']))[0]->columns,
            );
            $unitOfWork = $compiled->get('test.unit_of_work');
            static::assertTrue($unitOfWork->hasPendingChanges());
            $fixture->flush($unitOfWork);
            static::assertSame(2, $queries);
            static::assertFalse($unitOfWork->hasPendingChanges());
            static::assertSame(
                ['tags' => ['one', 'two'], 'aliases' => ['third']],
                $lines->withKey($line)->get(['tags', 'aliases']),
            );
            $lines->withKey($line)->expectColumnValue('tags', ['one', 'two'])->setColumn('tags', ['updated']);
            $fixture->flush($unitOfWork);
            static::assertSame(['tags' => ['updated']], $lines->withKey($line)->get(['tags']));
            $owner = new MappedKey(['owner' => 'one']);
            $lines->expectSameRowKeysForOwner($owner, [$line]);
            $lines->reconcileForOwner($owner, []);
            $fixture->flush($unitOfWork);
            static::assertSame([], $lines->rowsForOwner($owner));
            static::assertSame(1, CompileTimeRowsSchema::$definitions);
        } finally {
            if (!$storage instanceof InMemoryRowDatabase) {
                $storage->executeStatement(
                    'DROP TABLE pg_temp.mapped_definition_lines, pg_temp.mapped_definition_orders',
                );
            }
        }
    }

    public function test_compilation_rejects_a_missing_owner_definition(): void
    {
        $container = self::container([CompileTimeRowsSchema::LINES => ['owner' => 'owner']]);
        $container->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)->setSynthetic(true);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Owner definition "' . CompileTimeRowsSchema::ORDERS . '"');
        $container->compile();
    }

    public function test_compilation_requires_a_shared_adapter(): void
    {
        $container = self::container([CompileTimeRowsSchema::ORDERS]);
        $container->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)->setShared(false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('require a shared MappedTableAdapter');
        $container->compile();
    }

    public function test_compilation_rejects_a_service_that_cannot_bind_mapped_row_sets(): void
    {
        $container = self::container([CompileTimeRowsSchema::ORDERS]);
        $container->register(MappedTableAdapter::class, InMemoryRowDatabase::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must extend MappedTableAdapter');
        $container->compile();
    }

    public function test_attribute_declares_a_guard_in_a_mapped_table_only_container(): void
    {
        $container = self::container([CompileTimeRowsSchema::ORDERS]);
        $container->register(InMemoryRowDatabase::class, InMemoryRowDatabase::class);
        $container
            ->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)
            ->setArguments([new Reference(InMemoryRowDatabase::class)]);
        $container
            ->register(AttributeGuardReader::class, AttributeGuardReader::class)
            ->setAutowired(true)
            ->setPublic(true);
        $container->getDefinition(InMemoryRowDatabase::class)->setPublic(true);
        $container->compile();

        $reader = $container->get(AttributeGuardReader::class);
        static::assertInstanceOf(AttributeGuardReader::class, $reader);
        static::assertSame('empty', $reader->observe('one'));
        $storage = $container->get(InMemoryRowDatabase::class);
        static::assertInstanceOf(InMemoryRowDatabase::class, $storage);
        $storage->seedRow('mapped_definition_orders', 'id', ['id' => 'one']);

        $this->expectException(\Ultra\UnitOfWork\Exception\ConcurrencyException::class);
        $storage->transactional($reader->flush(...));
    }

    /** @return iterable<string, array{0: list<QueryGuardDefinition>, 1: string, 2?: bool}> */
    public static function invalidQueryGuards(): iterable
    {
        yield 'empty dependencies' => [[new QueryGuardDefinition('guard', [])], 'at least one dependency'];
        yield 'missing dependency' => [
            [new QueryGuardDefinition('guard', [
                new QueryGuardDependency(CompileTimeRowsSchema::LINES, 'owner'),
            ])],
            'watches unknown row set definition',
        ];
        yield 'unknown watched column' => [
            [new QueryGuardDefinition('guard', [
                new QueryGuardDependency(CompileTimeRowsSchema::ORDERS, 'id', ['unknown']),
            ])],
            'Unknown column "unknown"',
        ];
        yield 'ambiguous guard name' => [
            [
                new QueryGuardDefinition('guard', [new QueryGuardDependency(CompileTimeRowsSchema::ORDERS, 'id')]),
                new QueryGuardDefinition('guard', [new QueryGuardDependency(CompileTimeRowsSchema::ORDERS, 'id')]),
            ],
            'unique, non-empty name',
        ];
        yield 'invalid value column' => [
            [new QueryGuardDefinition('guard', [
                new QueryGuardDependency(CompileTimeRowsSchema::LINES, 'tags'),
            ])],
            'needs a non-null int or string value column',
            true,
        ];
    }

    #[DataProvider('invalidQueryGuards')]
    public function test_invalid_query_guards_fail_during_container_compilation(
        array $guards,
        string $message,
        bool $registerLines = false,
    ): void {
        $selections = [CompileTimeRowsSchema::ORDERS];
        if ($registerLines) {
            $selections[CompileTimeRowsSchema::LINES] = ['owner' => 'owner'];
        }
        $container = self::container($selections, $guards);

        $container->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)->setSynthetic(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);
        $container->compile();
    }

    public function test_compilation_requires_the_concrete_backend_of_a_synthetic_adapter(): void
    {
        $container = self::container([CompileTimeRowsSchema::ORDERS]);
        $container->register(MappedTableAdapter::class, MappedTableAdapter::class)->setSynthetic(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must declare its concrete class');
        $container->compile();
    }

    public function test_defined_rows_require_the_declared_representations_and_immutable_keys(): void
    {
        [, $rows] = $this->ownedRows('memory');
        $key = new MappedKey(['tenant' => 'one', 'owner' => 'order', 'id' => 'line']);
        $invalid = [
            static fn() => $rows->withKey(new MappedKey(['tenant' => 'one', 'owner' => 1, 'id' => 'line']))->get(),
            static fn() => $rows->rowsForOwner(new MappedKey(['owner' => 'order'])),
            static fn() => $rows->withKey($key)->setColumn('status', 3),
            static fn() => $rows->withKey($key)->setColumn('owner', 'moved'),
            static fn() => $rows->withKey($key)->expectColumnValue('unknown', 'value'),
            static fn() => $rows->withKey($key)->get(['unknown']),
        ];
        foreach ($invalid as $operation) {
            try {
                $operation();
                static::fail('Invalid input must be rejected by the mapped row set.');
            } catch (\InvalidArgumentException) {
                static::assertFalse($rows->hasPendingChanges());
            }
        }

        $rows->withKey($key)->insert()->setColumn('status', null);
        static::assertSame(['status' => null], $rows->withKey($key)->get(['status']));
    }

    #[DataProvider('backends')]
    public function test_defined_projection_exposes_only_mapped_columns_and_checks_nullable_values(string $backend): void
    {
        $fixture = $this->fixture($backend, ['id', 'owner']);
        $owner = ['owner' => 'order'];
        $key = new MappedKey([...$owner, 'id' => 'line']);
        $fixture->seed([...$key->values, 'status' => 'outside the definition']);
        $table = $fixture->tableName;
        $schema = new SchemaDefinition([
            new TableDefinition(
                $table,
                columns: [
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('owner', type: ColumnType::Text),
                    new ColumnDefinition('note', type: ColumnType::Text, nullable: true),
                    new ColumnDefinition('status', type: ColumnType::Text, nullable: true),
                ],
                primaryKey: ['id', 'owner'],
            ),
        ]);
        $keyOnlyAdapter = $fixture->storage instanceof Connection
            ? new PostgresMappedTableAdapter($fixture->storage)
            : new InMemoryMappedTableAdapter($fixture->storage);
        $keys = $keyOnlyAdapter->compileRowSet(SchemaRowSet::fromTable($schema, $table, columns: [], ownerColumns: ['owner']))();
        static::assertSame([], $keys->rowsForOwner($owner)[0]->columns);
        static::assertSame($key->values, $keys->withKey($key)->get());

        $rows = $fixture->adapter->compileRowSet(SchemaRowSet::fromTable($schema, $table, columns: ['note'], ownerColumns: ['owner']))();
        static::assertSame(['note' => null], $rows->rowsForOwner($owner)[0]->columns);
        $rows->withKey($key)->expectColumnValue('note', null);
        $fixture->flush($rows);
        static::assertFalse($rows->hasPendingChanges());
    }

    public function test_defined_collections_cannot_reconcile_the_entire_table(): void
    {
        [, $rows] = $this->ownedRows('memory');
        $this->expectException(\LogicException::class);
        $rows->reconcileRows([]);
    }

    public function test_key_columns_must_be_known_and_non_nullable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TableDefinition(
            'line',
            columns: [new ColumnDefinition('id', type: ColumnType::Text, nullable: true)],
            primaryKey: ['id'],
        );
    }

    /** @param array<array-key, string|array{owner: string}> $selections @param list<QueryGuardDefinition> $guards */
    private static function container(array $selections, array $guards = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new SchemaExtension()->build($container);
        new MappedTableExtension($guards, $selections)->build($container);
        $container->register(CompileTimeRowsSchema::class)->setAutoconfigured(true);

        return $container;
    }
}

final class CompileTimeRowsSchema
{
    public const string ORDERS = 'mapped_definition_orders';
    public const string LINES = 'mapped_definition_lines';

    public static int $definitions = 0;

    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        ++self::$definitions;

        return new SchemaDefinition([
            new TableDefinition(
                self::ORDERS,
                columns: [new ColumnDefinition('id', type: ColumnType::Text)],
                primaryKey: ['id'],
            ),
            new TableDefinition(
                self::LINES,
                columns: [
                    new ColumnDefinition('owner', type: ColumnType::Text),
                    new ColumnDefinition('id', type: ColumnType::Text),
                    new ColumnDefinition('tags', type: SqlType::fromSql('text[]'), nullable: true),
                    new ColumnDefinition('aliases', type: SqlType::fromSql('text[]'), nullable: true),
                ],
                primaryKey: ['owner', 'id'],
                foreignKeys: ['owner' => new ForeignKeyDefinition(self::ORDERS, ['owner' => 'id'])],
            ),
        ]);
    }
}

final readonly class DirectLineWriter
{
    public function __construct(
        #[Autowire(service: CompileTimeRowsSchema::LINES)]
        public MappedRowSet $lines,
        #[Autowire(service: QueryGuard::class . '.lines')]
        public QueryGuard $guard,
    ) {}
}

final readonly class AttributeGuardReader
{
    public function __construct(
        #[DefineQueryGuard('orders', [new QueryGuardDependency(CompileTimeRowsSchema::ORDERS, 'id')])]
        private QueryGuard $guard,
        private InMemoryRowDatabase $storage,
        private MappedTableUnitOfWork $unitOfWork,
    ) {}

    public function observe(string $id): string
    {
        return $this->guard->observe($id, fn(): string => false === $this->storage->row(
            'mapped_definition_orders',
            'id',
            $id,
        )
                ? 'empty'
                : 'present');
    }

    public function flush(): void
    {
        $this->unitOfWork->flush();
    }
}
