<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\Tests\Support\MapperTestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperCompilerPass;
use Ultra\Mapper_Bundle\TagNames;
use Ultra\Mapper_Compiler\NormalizerGenerator;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
#[CoversNamespace('Ultra\Mapper_Bundle')]
final class CompilationLifecycleTest extends MapperTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_standalone_use_has_a_working_default_cache(): void
    {
        $cache = dirname(__DIR__, 3).'/var/normalizer_cache';
        $this->extraDirectories[] = $cache;
        $this->removeDirectory($cache);
        $normalizer = new UltraNormalizer();

        self::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
        self::assertNotEmpty($this->generatedFiles($cache));
    }

    public function test_first_use_generates_a_file_and_the_instance_reuses_its_normalizer(): void
    {
        $normalizer = $this->normalizer();
        $cache = $this->directory.'/normalizers';
        self::assertSame([], $this->generatedFiles($cache));

        self::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
        $files = $this->generatedFiles($cache);
        self::assertNotEmpty($files);
        foreach (array_keys($files) as $file) {
            unlink($file);
        }

        self::assertEquals(new Foo(42, 'bar'), $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class));
        self::assertSame([], $this->generatedFiles($cache));
    }

    public function test_precompiled_file_is_loaded_without_being_regenerated(): void
    {
        $cache = $this->directory.'/normalizers';
        $container = new ContainerBuilder();
        $container->setDefinition('record', new Definition(\stdClass::class)
            ->addResourceTag(TagNames::NORMALIZABLE, ['class' => Foo::class]));
        new MapperCompilerPass($cache, generationNamespace: $this->generationNamespace)->process($container);
        $files = $this->generatedFiles($cache);
        self::assertNotEmpty($files);
        foreach ($files as $file => $code) {
            file_put_contents($file, $code."\n\$GLOBALS['mapper_loaded_artifacts'][] = __FILE__;\n");
        }
        $compiled = $this->generatedFiles($cache);
        $GLOBALS['mapper_loaded_artifacts'] = [];
        try {
            $normalizer = $this->normalizer();

            self::assertEquals(new Foo(42, 'bar'), $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class));

            self::assertNotEmpty($GLOBALS['mapper_loaded_artifacts']);
            self::assertSame($compiled, $this->generatedFiles($cache));
        } finally {
            unset($GLOBALS['mapper_loaded_artifacts']);
        }
    }

    #[DataProvider('generatedClassNames')]
    public function test_generated_files_can_be_loaded_with_the_requested_class_name(string $class): void
    {
        $class .= '_'.bin2hex(random_bytes(8));
        $normalizer = $this->normalizer();
        $file = $this->directory.'/normalizer.php';
        file_put_contents($file, NormalizerGenerator::for(Foo::class, $class, [])->generate());
        require $file;
        $generated = new $class();

        self::assertSame(['id' => 42, 'name' => 'bar'], $generated->toScalar(new Foo(42, 'bar'), $normalizer));
        self::assertEquals(new Foo(42, 'bar'), $generated->fromScalar(
            ['id' => 42, 'name' => 'bar'], Foo::class, $normalizer, new DenormalizationContext(),
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function generatedClassNames(): iterable
    {
        yield 'global class' => ['MapperTestGeneratedNormalizer'];
        yield 'qualified class with leading separator' => ['\Ultra\Mapper\Tests\Generated\FooNormalizer'];
    }
}
