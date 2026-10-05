<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\Tests\Support\MapperTestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizerGenerator;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CompilationLifecycleTest extends MapperTestCase
{
    public function test_standalone_use_has_a_working_default_cache(): void
    {
        $cache = dirname(__DIR__, 3).'/var/normalizer_cache';
        $this->extraDirectories[] = $cache;
        $this->removeDirectory($cache);
        $normalizer = new UltraNormalizer();

        self::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
        self::assertFileExists($cache.'/__Normalizer_Ultra_Mapper_Tests_Fixtures_Foo.php');
    }

    public function test_first_use_generates_a_file_and_the_instance_reuses_its_normalizer(): void
    {
        $normalizer = $this->normalizer();
        $file = $this->directory.'/normalizers/__Normalizer_Ultra_Mapper_Tests_Fixtures_Foo.php';
        self::assertFileDoesNotExist($file);

        self::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
        self::assertFileExists($file);
        unlink($file);

        self::assertEquals(new Foo(42, 'bar'), $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class));
        self::assertFileDoesNotExist($file);
    }

    public function test_precompiled_file_is_loaded_without_being_regenerated(): void
    {
        $normalizer = $this->normalizer();
        $class = 'Ultra\Normalizer\Generated\__Normalizer_Ultra_Mapper_Tests_Fixtures_Foo';
        $file = $this->directory.'/normalizers/__Normalizer_Ultra_Mapper_Tests_Fixtures_Foo.php';
        $code = NormalizerGenerator::for(Foo::class, $class, [])->generate()."\n// Precompiled artifact\n";
        file_put_contents($file, $code);
        self::assertFalse(class_exists($class, false));

        self::assertEquals(new Foo(42, 'bar'), $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class));

        self::assertTrue(class_exists($class, false));
        self::assertSame($code, file_get_contents($file));
    }

    #[DataProvider('generatedClassNames')]
    public function test_generated_files_can_be_loaded_with_the_requested_class_name(string $class): void
    {
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
