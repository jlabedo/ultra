<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Ultra\Mapper\Tests\Fixtures\BrokenValue;
use Ultra\Mapper\Tests\Fixtures\CompoundId;
use Ultra\Mapper\Tests\Fixtures\CompoundIdNormalizer;
use Ultra\Mapper\Tests\Fixtures\ExternalId;
use Ultra\Mapper\Tests\Fixtures\ExternalIdNormalizer;
use Ultra\Mapper\Tests\Fixtures\HookedId;
use Ultra\Mapper\Tests\Fixtures\ImpreciseIdNormalizer;
use Ultra\Mapper\Tests\Fixtures\MalformedArrayNormalizer;
use Ultra\Mapper\Tests\Fixtures\NullableId;
use Ultra\Mapper\Tests\Fixtures\ObjectUnionId;
use Ultra\Mapper\Tests\Fixtures\OuterValue;
use Ultra\Mapper\Tests\Fixtures\Status;
use Ultra\Mapper\Tests\Fixtures\UndocumentedArrayNormalizer;
use Ultra\Mapper\Tests\Fixtures\UnitEnum;
use Ultra\Mapper\Tests\Fixtures\UntypedId;
use Ultra\Mapper\Tests\Support\MapperTestCase;
use Ultra\Mapper_Compiler\NormalizationShapeAnalyzer;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
final class NormalizationShapeTest extends MapperTestCase
{
    /** @param class-string $type */
    #[DataProvider('shapes')]
    public function test_shapes_describe_the_representation_independently_of_runtime_generation(
        string $type,
        array $runtimeNormalizers,
        array $shape,
    ): void {
        static::assertSame($shape, new NormalizationShapeAnalyzer($runtimeNormalizers)->forClass($type));
    }

    /** @return iterable<string, array{class-string, array, array}> */
    public static function shapes(): iterable
    {
        $compound = [
            'kind' => 'object',
            'fields' => [
                'left' => ['kind' => 'scalar', 'types' => ['string']],
                'right' => ['kind' => 'scalar', 'types' => ['int']],
            ],
        ];
        yield 'stored fields are sorted and static fields excluded' => [CompoundId::class, [], $compound];
        yield 'virtual fields excluded' => [HookedId::class, [], $compound];
        yield 'runtime scalar union' => [
            ExternalId::class,
            [ExternalId::class => ExternalIdNormalizer::class],
            ['kind' => 'scalar', 'types' => ['int', 'string']],
        ];
        yield 'runtime closed array shape' => [
            CompoundId::class,
            [CompoundId::class => CompoundIdNormalizer::class],
            [
                'kind' => 'object',
                'fields' => [
                    'left' => ['kind' => 'scalar', 'types' => ['string']],
                    'right' => ['kind' => 'scalar', 'types' => ['int', 'string']],
                ],
            ],
        ];
        yield 'nested value wrappers' => [OuterValue::class, [], ['kind' => 'scalar', 'types' => ['int']]];
        yield 'backed enum' => [Status::class, [], ['kind' => 'scalar', 'types' => ['string']]];
    }

    public function test_property_shapes_are_available_to_storage_compilers(): void
    {
        static::assertSame(
            ['kind' => 'scalar', 'types' => ['string']],
            new NormalizationShapeAnalyzer()->forProperty(new \ReflectionProperty(CompoundId::class, 'left')),
        );
    }

    /** @param class-string $type */
    #[DataProvider('invalidShapes')]
    public function test_imprecise_shapes_are_rejected_with_the_origin(
        string $type,
        array $runtimeNormalizers,
        string $reason,
        string $origin,
    ): void {
        try {
            new NormalizationShapeAnalyzer($runtimeNormalizers)->forClass($type);
            static::fail('Expected an imprecise shape to be rejected.');
        } catch (\LogicException $exception) {
            static::assertStringContainsString($reason, $exception->getMessage());
            static::assertStringContainsString($origin, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{class-string, array, string, string}> */
    public static function invalidShapes(): iterable
    {
        yield 'nullable runtime representation' => [
            ExternalId::class,
            [ExternalId::class => ImpreciseIdNormalizer::class],
            'cannot be nullable',
            ImpreciseIdNormalizer::class . '::toScalar()',
        ];
        yield 'array without shape' => [
            ExternalId::class,
            [ExternalId::class => UndocumentedArrayNormalizer::class],
            'closed PHPDoc array shape',
            UndocumentedArrayNormalizer::class,
        ];
        yield 'malformed array field' => [
            ExternalId::class,
            [ExternalId::class => MalformedArrayNormalizer::class],
            'unsupported array-shape field',
            MalformedArrayNormalizer::class,
        ];
        yield 'untyped property' => [UntypedId::class, [], 'must declare a type', UntypedId::class . '::$id'];
        yield 'nullable property' => [NullableId::class, [], 'cannot be nullable', NullableId::class . '::$id'];
        yield 'object union' => [ObjectUnionId::class, [], 'unsupported union', ObjectUnionId::class . '::$id'];
        yield 'non-backed enum' => [UnitEnum::class, [], 'Non-backed enum', UnitEnum::class];
        yield 'invalid value wrapper' => [BrokenValue::class, [], 'exactly one instance property', BrokenValue::class];
        yield 'empty object' => [ExternalId::class, [], 'no instance properties', ExternalId::class];
    }
}
