<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Mapper\Attribute\AsValue;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\NormalizerInterface;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\GeneratedNormalizerFile;
use Ultra\Mapper_Compiler\NormalizerGenerator;

#[AsValue]
final class HookedEmail
{
    public string $domain {
        get => substr($this->value, (int) strpos($this->value, '@') + 1);
    }

    public function __construct(public readonly string $value) {}
}

final class HookedSlot
{
    public string $day {
        get => substr($this->start, 0, 10);
    }

    public function __construct(
        public readonly string $start,
        public readonly HookedEmail $owner,
    ) {}
}

/**
 * @internal
 */
#[CoversClass(NormalizerGenerator::class)]
#[CoversClass(GeneratedNormalizerFile::class)]
final class NormalizerGeneratorTest extends TestCase
{
    public function test_generates_a_working_normalizer_without_laminas_code(): void
    {
        [$generatedNormalizer, $code] = $this->generate(Foo::class);

        self::assertStringNotContainsString('Laminas', $code);

        $normalizer = new UltraNormalizer();

        self::assertSame(['id' => 42, 'name' => 'bar'], $generatedNormalizer->toScalar(new Foo(42, 'bar'), $normalizer));
        self::assertEquals(
            new Foo(42, 'bar'),
            $generatedNormalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class, $normalizer, new DenormalizationContext()),
        );
    }

    public function test_virtual_properties_are_neither_normalized_nor_denormalized(): void
    {
        [$generatedNormalizer] = $this->generate(HookedSlot::class);
        $normalizer = new UltraNormalizer();
        $slot = new HookedSlot('2026-09-10 10:00', new HookedEmail('ada@example.test'));

        $scalar = $generatedNormalizer->toScalar($slot, $normalizer);
        $restored = $generatedNormalizer->fromScalar($scalar, HookedSlot::class, $normalizer, new DenormalizationContext());

        self::assertSame(['start' => '2026-09-10 10:00', 'owner' => 'ada@example.test'], $scalar);
        self::assertEquals($slot, $restored);
        self::assertSame('2026-09-10', $restored->day);
        self::assertSame('example.test', $restored->owner->domain);
    }

    /**
     * @param class-string $class
     *
     * @return array{NormalizerInterface, string}
     */
    private function generate(string $class): array
    {
        $normalizerClass = 'Ultra\\Mapper_Compiler\\Tests\\Generated\\__Normalizer_'
            .(new \ReflectionClass($class))->getShortName().'_'
            .str_replace('.', '_', uniqid('', true));
        $code = NormalizerGenerator::for($class, $normalizerClass, [])->generate();

        $filePath = sys_get_temp_dir().'/'.str_replace('\\', '_', $normalizerClass).'.php';
        file_put_contents($filePath, $code);

        try {
            require_once $filePath;
        } finally {
            @unlink($filePath);
        }

        $generatedNormalizer = new $normalizerClass();
        \assert($generatedNormalizer instanceof NormalizerInterface);

        return [$generatedNormalizer, $code];
    }
}
