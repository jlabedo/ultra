<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ultra\Mapper\DenormalizationContext;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;

final readonly class CompoundId
{
    public function __construct(
        public string $left,
        public int $right,
    ) {}
}

final class ExternalId {}

final class ExternalIdNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [ExternalId::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): int|string
    {
        return 'external';
    }

    public function fromScalar(array|bool|float|int|string|null $value, string $type, UltraNormalizer $normalizer, DenormalizationContext $context): mixed
    {
        return new ExternalId();
    }
}

final class CompoundIdNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [CompoundId::class];
    }

    /**
     * @return array{
     *     left: string,
     *     right: int|string,
     * }
     */
    public function toScalar(mixed $value, UltraNormalizer $normalizer): array
    {
        return ['left' => 'left', 'right' => 1];
    }

    public function fromScalar(array|bool|float|int|string|null $value, string $type, UltraNormalizer $normalizer, DenormalizationContext $context): mixed
    {
        return new CompoundId('left', 1);
    }
}

final class ImpreciseIdNormalizer implements RuntimeNormalizerInterface
{
    public static function handledTypes(): iterable
    {
        return [ExternalId::class];
    }

    public function toScalar(mixed $value, UltraNormalizer $normalizer): array|bool|float|int|string|null
    {
        return 'external';
    }

    public function fromScalar(array|bool|float|int|string|null $value, string $type, UltraNormalizer $normalizer, DenormalizationContext $context): mixed
    {
        return new ExternalId();
    }
}

/** @internal */
#[CoversClass(NormalizationShapeAnalyzer::class)]
final class NormalizationShapeAnalyzerTest extends TestCase
{
    public function test_plain_object_exposes_object_shape(): void
    {
        self::assertSame([
            'kind' => 'object',
            'fields' => [
                'left' => ['kind' => 'scalar', 'types' => ['string']],
                'right' => ['kind' => 'scalar', 'types' => ['int']],
            ],
        ], new NormalizationShapeAnalyzer()->forClass(CompoundId::class));
    }

    public function test_runtime_normalizer_return_type_exposes_scalar_shape(): void
    {
        $analyzer = new NormalizationShapeAnalyzer([
            ExternalId::class => ExternalIdNormalizer::class,
        ]);

        self::assertSame(['kind' => 'scalar', 'types' => ['int', 'string']], $analyzer->forClass(ExternalId::class));
    }

    public function test_runtime_normalizer_closed_array_shape_exposes_object_shape(): void
    {
        $analyzer = new NormalizationShapeAnalyzer([
            CompoundId::class => CompoundIdNormalizer::class,
        ]);

        self::assertSame([
            'kind' => 'object',
            'fields' => [
                'left' => ['kind' => 'scalar', 'types' => ['string']],
                'right' => ['kind' => 'scalar', 'types' => ['int', 'string']],
            ],
        ], $analyzer->forClass(CompoundId::class));
    }

    public function test_imprecise_runtime_normalizer_shape_is_rejected(): void
    {
        $analyzer = new NormalizationShapeAnalyzer([
            ExternalId::class => ImpreciseIdNormalizer::class,
        ]);

        $this->expectException(\LogicException::class);
        $analyzer->forClass(ExternalId::class);
    }
}
