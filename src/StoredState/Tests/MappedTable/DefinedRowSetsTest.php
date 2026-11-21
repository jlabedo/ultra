<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;

/** @internal */
#[CoversNamespace('Ultra\MappedTable')]
final class DefinedRowSetsTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_definitions_compile_to_shared_row_sets_using_the_application_adapter(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $storage = $fixture->storage;
        if ($storage instanceof InMemoryRowDatabase) {
            $factory = new InMemoryMappedRowSetAdapterFactory($storage);
        } else {
            $storage->executeStatement(
                'CREATE TEMP TABLE mapped_definition_lines (owner TEXT, id TEXT, tags TEXT[], PRIMARY KEY (owner, id))',
            );
            $factory = new PostgresMappedRowSetAdapterFactory($storage);
        }
        CompileTimeLineDefinition::$mappingCalls = 0;
        CompileTimeLineDefinition::$postgresCalls = 0;

        try {
            $container = new ContainerBuilder();
            new MappedTableExtension()->build($container);
            $container
                ->register(MappedRowSetAdapterFactory::class, $factory::class)
                ->setSynthetic(true)
                ->setPublic(true);
            $container->register(CompileTimeLineDefinition::class)->setAutoconfigured(true);
            $container->register(DirectLineWriter::class)->setAutowired(true)->setPublic(true);
            $container->setAlias('test.lines', CompileTimeLineDefinition::class)->setPublic(true);
            $container->compile();

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
            $compiled->set(MappedRowSetAdapterFactory::class, $factory);
            $lines = $compiled->get('test.lines');
            $writer = $compiled->get(DirectLineWriter::class);
            static::assertSame($lines, $writer->lines);
            $line = new MappedKey(['owner' => 'one', 'id' => 'line']);
            $writer->lines->withKey($line)->insert()->setColumn('tags', ['one', 'two']);
            static::assertSame(
                ['tags' => ['one', 'two']],
                $lines->rowsForOwner(new MappedKey(['owner' => 'one']))[0]->columns,
            );
            $fixture->flush($lines);
            static::assertSame(['tags' => ['one', 'two']], $lines->row($line, ['tags']));
            static::assertSame(1, CompileTimeLineDefinition::$mappingCalls);
            static::assertSame('postgres' === $backend ? 1 : 0, CompileTimeLineDefinition::$postgresCalls);
        } finally {
            if (!$storage instanceof InMemoryRowDatabase) {
                $storage->executeStatement('DROP TABLE pg_temp.mapped_definition_lines');
            }
        }
    }

    public function test_defined_rows_require_the_declared_representations_and_immutable_keys(): void
    {
        [, $rows] = $this->ownedRows('memory');
        $key = new MappedKey(['tenant' => 'one', 'owner' => 'order', 'id' => 'line']);
        $invalid = [
            static fn() => $rows->row(new MappedKey(['tenant' => 'one', 'owner' => 1, 'id' => 'line'])),
            static fn() => $rows->rowsForOwner(new MappedKey(['owner' => 'order'])),
            static fn() => $rows->withKey($key)->setColumn('status', 3),
            static fn() => $rows->withKey($key)->setColumn('owner', 'moved'),
            static fn() => $rows->withKey($key)->expectColumnValue('unknown', 'value'),
            static fn() => $rows->row($key, ['unknown']),
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
        static::assertSame(['status' => null], $rows->row($key, ['status']));
    }

    #[DataProvider('backends')]
    public function test_defined_projection_exposes_only_mapped_columns_and_checks_nullable_values(string $backend): void
    {
        $fixture = $this->fixture($backend);
        $key = new MappedKey(['id' => 'line']);
        $fixture->seed(['id' => 'line', 'status' => 'outside the definition']);
        $keys = $fixture->defined(
            new MappedRowSetMapping($fixture->tableName, ['id' => new MappedColumn('string')], ['id']),
        );
        static::assertSame([], $keys->rows()[0]->columns);
        static::assertSame(['id' => 'line'], $keys->row($key));

        $rows = $fixture->defined(
            new MappedRowSetMapping(
                $fixture->tableName,
                ['id' => new MappedColumn('string'), 'note' => new MappedColumn('string', nullable: true)],
                ['id'],
            ),
        );
        static::assertSame(['note' => null], $rows->rows()[0]->columns);
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
        new MappedRowSetMapping('line', ['id' => new MappedColumn('string', nullable: true)], ['id']);
    }
}

final class CompileTimeLineDefinition implements MappedRowSetDefinition, PostgresMappedRowSetConfiguration
{
    public static int $mappingCalls = 0;
    public static int $postgresCalls = 0;

    public function mapping(): MappedRowSetMapping
    {
        ++self::$mappingCalls;

        return new MappedRowSetMapping(
            'mapped_definition_lines',
            [
                'owner' => new MappedColumn('string'),
                'id' => new MappedColumn('string'),
                'tags' => new MappedColumn('array', nullable: true),
            ],
            ['owner', 'id'],
            ['owner'],
        );
    }

    public function postgresColumnTypes(): array
    {
        ++self::$postgresCalls;

        return ['tags' => PostgresMappedColumnType::textArray()];
    }
}

final readonly class DirectLineWriter
{
    public function __construct(
        #[Autowire(service: CompileTimeLineDefinition::class)]
        public MappedRowSet $lines,
    ) {}
}
