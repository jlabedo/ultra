<?php

declare(strict_types=1);

namespace Ultra\Mapper_Bundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mapper_Bundle\DependencyInjection\Compiler\MapperCompilerPass;
use Ultra\Mapper_Bundle\TagNames;

final readonly class MapperCompilerPassFixture
{
    public function __construct(public int $id) {}
}

/**
 * @internal
 */
#[CoversClass(MapperCompilerPass::class)]
final class MapperCompilerPassTest extends TestCase
{
    public function test_it_generates_normalizer_files_directly(): void
    {
        $outputDir = sys_get_temp_dir().'/ultra_mapper_compiler_pass_'.bin2hex(random_bytes(4));
        $container = new ContainerBuilder();
        $container->setDefinition(
            MapperCompilerPassFixture::class,
            new Definition(MapperCompilerPassFixture::class)
                ->addResourceTag(TagNames::NORMALIZABLE, ['class' => MapperCompilerPassFixture::class])
        );

        new MapperCompilerPass($outputDir)->process($container);

        $cacheFile = $outputDir.'/__Normalizer_Ultra_Mapper_Bundle_Tests_MapperCompilerPassFixture.php';

        self::assertFileExists($cacheFile);
        self::assertStringContainsString(
            'class __Normalizer_Ultra_Mapper_Bundle_Tests_MapperCompilerPassFixture',
            (string) file_get_contents($cacheFile),
        );
        self::assertFalse($container->hasDefinition('Ultra\Mapper_Bundle\Cache\MapperCacheWarmer'));
    }
}
