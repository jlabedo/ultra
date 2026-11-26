<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\ColumnCodec;
use Ultra\MappedTable\InMemoryMappedTableAdapter;
use Ultra\MappedTable\InMemoryRowDatabase;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\Schema\SchemaRowSet;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\Schema\Attribute\DefineSchema;
use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\SqlType;

#[CoversNamespace('Ultra\MappedTable')]
final class ColumnCodecRegistrationTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_manual_registration_adds_a_sql_type_with_its_own_representation(string $backend): void
    {
        $fixture = $this->fixture($backend, columnTypes: ['level' => 'smallint']);
        $rows = $fixture->newRows(codecs: ['smallint' => new LevelCodec(new LevelPrefix('level:'))]);
        $row = $rows->withKey('entry')->insert()->setColumn('level', 7);
        self::assertSame(['level' => 'level:7'], $row->get(['level']));
        $fixture->flush($rows);

        $row = $rows->withKey('entry');
        self::assertSame(['level' => 'level:7'], $row->observe(['level']));
        $row->expectColumnValue('level', 7)->setColumn('level', 8);
        $fixture->flush($rows);
        self::assertSame(['level' => 'level:8'], $rows->withKey('entry')->get(['level']));
    }

    public function test_the_dumped_container_uses_a_private_codec_service_for_a_sql_alias(): void
    {
        $builder = new ContainerBuilder();
        new SchemaExtension()->build($builder);
        $builder->register(CodecRegistrationSchema::class)->setAutoconfigured(true);
        new MappedTableExtension(rowSets: ['test.levels'])->build($builder);
        $builder->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)->setSynthetic(true);
        $builder->setAlias('public.levels', 'test.levels')->setPublic(true);
        $builder->register('test.level_prefix', LevelPrefix::class)->setArguments(['rank:']);
        $builder
            ->register('test.level_codec', LevelCodec::class)
            ->setArguments([new Reference('test.level_prefix')])
            ->addTag(MappedTableExtension::COLUMN_CODEC_TAG, ['type' => 'int4']);
        $builder->compile();

        $class = 'CompiledCodecRowSets' . bin2hex(random_bytes(6));
        $file = tempnam(sys_get_temp_dir(), 'mapped-column-codec-');
        assert(is_string($file));
        try {
            file_put_contents($file, new PhpDumper($builder)->dump(['class' => $class]));
            require $file;
        } finally {
            unlink($file);
        }

        $compiled = new $class();
        $compiled->set(MappedTableAdapter::class, new InMemoryMappedTableAdapter(new InMemoryRowDatabase()));
        $entry = $compiled->get('public.levels')->withKey('entry')->insert()->setColumn('level', 12);
        self::assertSame(['level' => 'rank:12'], $entry->get(['level']));
    }

    public function test_equivalent_sql_spellings_cannot_register_distinct_codecs(): void
    {
        $adapter = new InMemoryMappedTableAdapter(new InMemoryRowDatabase());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple codecs registered for SQL type "integer".');
        $adapter->compileRowSet(SchemaRowSet::fromTable(CodecRegistrationSchema::definition(), 'test.levels'), codecs: [
            'int4' => new LevelCodec(new LevelPrefix('first:')),
            'integer' => new LevelCodec(new LevelPrefix('second:')),
        ]);
    }
}

final class CodecRegistrationSchema
{
    #[DefineSchema]
    public static function definition(): SchemaDefinition
    {
        return new SchemaDefinition([new TableDefinition(
            'test.levels',
            columns: [
                new ColumnDefinition('id', type: 'text'),
                new ColumnDefinition('level', type: 'integer'),
            ],
            primaryKey: ['id'],
        )]);
    }
}

final readonly class LevelPrefix
{
    public function __construct(
        public string $text,
    ) {}
}

final class LevelCodec extends ColumnCodec
{
    public function __construct(
        private readonly LevelPrefix $prefix,
    ) {}

    public static function representation(): string
    {
        return 'string';
    }

    public function normalize(SqlType $type, mixed $value): string
    {
        return match (true) {
            is_int($value) => $this->prefix->text . $value,
            is_string($value) && str_starts_with($value, $this->prefix->text) => $value,
            default => throw new \InvalidArgumentException('Expected a level.'),
        };
    }

    public function encode(SqlType $type, mixed $value): int
    {
        return (int) substr($value, strlen($this->prefix->text));
    }
}
