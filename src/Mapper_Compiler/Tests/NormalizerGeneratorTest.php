<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\NormalizerInterface;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\GeneratedNormalizerFile;
use Ultra\Mapper_Compiler\NormalizerGenerator;

/**
 * @internal
 */
#[CoversClass(NormalizerGenerator::class)]
#[CoversClass(GeneratedNormalizerFile::class)]
final class NormalizerGeneratorTest extends TestCase
{
    public function test_generates_a_working_normalizer_without_laminas_code(): void
    {
        $normalizerClass = 'Ultra\\Mapper_Compiler\\Tests\\Generated\\__Normalizer_Foo_'
            .str_replace('.', '_', uniqid('', true));
        $file = NormalizerGenerator::for(Foo::class, $normalizerClass, []);
        $code = $file->generate();

        self::assertStringNotContainsString('Laminas', $code);

        $filePath = sys_get_temp_dir().'/'.str_replace('\\', '_', $normalizerClass).'.php';
        file_put_contents($filePath, $code);

        try {
            require_once $filePath;

            $generatedNormalizer = new $normalizerClass();
            \assert($generatedNormalizer instanceof NormalizerInterface);

            $normalizer = new UltraNormalizer();

            self::assertSame(['id' => 42, 'name' => 'bar'], $generatedNormalizer->toScalar(new Foo(42, 'bar'), $normalizer));
            self::assertEquals(
                new Foo(42, 'bar'),
                $generatedNormalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class, $normalizer, new DenormalizationContext()),
            );
        } finally {
            @unlink($filePath);
        }
    }
}
