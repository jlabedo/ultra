<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mapper\Tests\Fixtures\AssemblyRecord;
use Ultra\Mapper\Tests\Fixtures\InnerValue;
use Ultra\Mapper\Tests\Fixtures\MultiTypeRuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\SecondaryRuntimeEmail;
use Ultra\Mapper\Tests\Support\MapperTestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperCompilerPass;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Mapper_Bundle\TagNames;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
#[CoversNamespace('Ultra\Mapper_Bundle')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ContainerCompilationTest extends MapperTestCase
{
    public function test_configuration_compiles_tagged_and_attributed_types_with_runtime_normalizers(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', $this->directory.'/build');
        $container->setParameter('email_normalizer_class', MultiTypeRuntimeEmailNormalizer::class);
        new MapperExtension()->build($container);
        $container->getDefinition(UltraNormalizer::class)->setPublic(true);
        $container->setDefinition('record', new Definition(\stdClass::class)
            ->addResourceTag(TagNames::NORMALIZABLE, ['class' => AssemblyRecord::class]));
        $container->setDefinition(InnerValue::class, new Definition(InnerValue::class)
            ->setAutoconfigured(true)->setPublic(true));
        $container->setDefinition('email_normalizer', new Definition('%email_normalizer_class%')
            ->setAutoconfigured(true)->setPublic(true));

        $container->compile();

        $cache = $this->directory.'/build/mapper';
        self::assertFileExists($cache.'/__Normalizer_Ultra_Mapper_Tests_Fixtures_AssemblyRecord.php');
        self::assertFileExists($cache.'/__Normalizer_Ultra_Mapper_Tests_Fixtures_InnerValue.php');
        $normalizer = $container->get(UltraNormalizer::class);
        self::assertInstanceOf(UltraNormalizer::class, $normalizer);
        $normalizer->registerRuntimeNormalizer($container->get('email_normalizer'));
        $record = new AssemblyRecord(new RuntimeEmail('primary@example.test'), new SecondaryRuntimeEmail('secondary@example.test'));
        $representation = ['primary' => 'runtime-email:primary@example.test', 'secondary' => 'runtime-email:secondary@example.test'];

        self::assertSame($representation, $normalizer->toScalar($record));
        self::assertEquals($record, $normalizer->fromScalar($representation, AssemblyRecord::class));
        self::assertSame(42, $normalizer->toScalar(new InnerValue(42)));
        self::assertEquals(new InnerValue(42), $normalizer->fromScalar(42, InnerValue::class));
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_tag_configuration_is_rejected(string $tag, ?string $class, array $attributes, string $exception, string $reason): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition($class);
        if (TagNames::NORMALIZABLE === $tag) {
            $definition->addResourceTag($tag, $attributes);
        } else {
            $definition->addTag($tag, $attributes);
        }
        $container->setDefinition('invalid', $definition);
        $this->expectException($exception);
        $this->expectExceptionMessage($reason);

        new MapperCompilerPass($this->directory.'/normalizers')->process($container);
    }

    /** @return iterable<string, array{string, ?string, array, class-string<\Throwable>, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'normalizable service without a class' => [TagNames::NORMALIZABLE, null, [], \LogicException::class, 'must have a class'];
        yield 'missing normalizable class' => [TagNames::NORMALIZABLE, \stdClass::class, ['class' => 'Ultra\Mapper\Tests\MissingRecord'], \RuntimeException::class, 'missing class'];
        yield 'runtime service without a class' => [TagNames::RUNTIME_NORMALIZER, null, [], \LogicException::class, 'has no class'];
        yield 'runtime service without the interface' => [TagNames::RUNTIME_NORMALIZER, \stdClass::class, [], \LogicException::class, 'must implement'];
    }
}
