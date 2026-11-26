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
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\MappedTable\Tests\Support\RowSetTestCase;
use Ultra\MappedTable\MappedRowSetDefinition;

#[CoversNamespace('Ultra\MappedTable')]
final class ColumnCodecRegistrationTest extends RowSetTestCase
{
    #[DataProvider('backends')]
    public function test_manual_registration_configures_one_column_with_its_own_representation(string $backend): void
    {
        $fixture = $this->fixture($backend, columnTypes: ['level' => 'smallint']);
        $definition = new MappedRowSetDefinition($fixture->tableName, ['id', 'level', 'note'], ['id'], codecs: [
            'level' => new LevelCodec(new LevelPrefix('level:')),
        ]);
        $rows = $fixture->adapter->compileRowSet($definition)();
        $row = $rows->withKey('entry')->insert()->setColumns(['level' => 7, 'note' => '007']);
        self::assertSame(['level' => 'level:7'], $row->get(['level']));
        $fixture->flush($rows);

        $row = $rows->withKey('entry');
        self::assertSame(['level' => 'level:7', 'note' => '007'], $row->observe(['level', 'note']));
        $row->expectColumnValue('level', 7)->setColumn('level', 8);
        $fixture->flush($rows);
        self::assertSame(['level' => 'level:8'], $rows->withKey('entry')->get(['level']));
    }

    public function test_the_dumped_container_uses_a_private_codec_service_for_one_mapped_column(): void
    {
        $builder = new ContainerBuilder();
        new MappedTableExtension(rowSets: [
            'test.levels' => new MappedRowSetDefinition('levels', ['id', 'level'], ['id'], codecs: [
                'level' => 'test.level_codec',
            ]),
        ])->build($builder);
        $builder->register(MappedTableAdapter::class, InMemoryMappedTableAdapter::class)->setSynthetic(true);
        $builder->setAlias('public.levels', 'test.levels')->setPublic(true);
        $builder->register('test.level_prefix', LevelPrefix::class)->setArguments(['rank:']);
        $builder
            ->register('test.level_codec', LevelCodec::class)
            ->setArguments([new Reference('test.level_prefix')]);
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

    public function test_a_codec_requires_a_declared_column(): void
    {
        $definition = new MappedRowSetDefinition('levels', ['id', 'level'], ['id'], codecs: [
            'typo' => new LevelCodec(new LevelPrefix('level:')),
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column "typo"');
        (new InMemoryMappedTableAdapter(new InMemoryRowDatabase()))->compileRowSet($definition);
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

    public function normalize(mixed $value): string
    {
        return match (true) {
            is_int($value) => $this->prefix->text . $value,
            is_string($value) && str_starts_with($value, $this->prefix->text) => $value,
            default => throw new \InvalidArgumentException('Expected a level.'),
        };
    }

    public function encode(mixed $value): int
    {
        return (int) substr($value, strlen($this->prefix->text));
    }
}
