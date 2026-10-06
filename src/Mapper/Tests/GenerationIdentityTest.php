<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ultra\Mapper\Tests\Fixtures\Collision\A\B as NestedB;
use Ultra\Mapper\Tests\Fixtures\Collision\A_B;
use Ultra\Mapper\Tests\Fixtures\Collision\A_sB;
use Ultra\Mapper\Tests\Fixtures\Collision_A\B;
use Ultra\Mapper\Tests\Fixtures\CustomerWithRuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\Foo;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmail;
use Ultra\Mapper\Tests\Fixtures\RuntimeEmailNormalizer;
use Ultra\Mapper\Tests\Support\MapperTestCase;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Mapper_Bundle\MapperCompilerPass;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Mapper_Bundle\TagNames;

/** @internal */
#[CoversNamespace('Ultra\Mapper')]
#[CoversNamespace('Ultra\Mapper_Compiler')]
#[CoversNamespace('Ultra\Mapper_Bundle')]
final class GenerationIdentityTest extends MapperTestCase
{
    #[DataProvider('collidingNames')]
    public function test_types_with_ambiguous_names_coexist_in_one_configuration(bool $precompile, string $firstType, string $secondType): void
    {
        $cache = $this->directory.'/normalizers';
        if ($precompile) {
            $container = new ContainerBuilder();
            foreach ([$firstType, $secondType] as $type) {
                $container->setDefinition($type, new Definition(\stdClass::class)
                    ->addResourceTag(TagNames::NORMALIZABLE, ['class' => $type]));
            }
            new MapperCompilerPass($cache, generationNamespace: $this->generationNamespace)->process($container);
        }
        $normalizer = $this->normalizer();
        $first = new $firstType(42);
        $second = new $secondType('Ada');

        self::assertSame(['id' => 42], $normalizer->toScalar($first));
        self::assertSame(['name' => 'Ada'], $normalizer->toScalar($second));
        self::assertEquals($first, $normalizer->fromScalar(['id' => 42], $firstType));
        self::assertEquals($second, $normalizer->fromScalar(['name' => 'Ada'], $secondType));
        self::assertCount(2, $this->generatedFiles($cache));
    }

    /** @return iterable<string, array{bool, class-string, class-string}> */
    public static function collidingNames(): iterable
    {
        foreach ([false, true] as $precompile) {
            $mode = $precompile ? 'precompiled artifacts' : 'first-use generation';
            yield $mode.': flattened names' => [$precompile, A_B::class, B::class];
            yield $mode.': literal separator escape' => [$precompile, A_sB::class, NestedB::class];
        }
    }

    public function test_configurations_coexist_in_the_same_process_and_artifact_directory(): void
    {
        $namespace = 'Ultra\\Mapper\\Tests\\Generated\\Compilation'.bin2hex(random_bytes(8));
        $plainContainer = $this->container($namespace.'\\Plain', false);
        $plainContainer->compile();
        $cache = $this->directory.'/build/mapper';
        self::assertCount(1, $this->generatedFiles($cache));
        $runtimeContainer = $this->container($namespace.'\\Runtime', true);
        $runtimeContainer->compile();
        $compiled = $this->generatedFiles($cache);
        self::assertCount(2, $compiled);
        $plain = $plainContainer->get(UltraNormalizer::class);
        $runtime = $runtimeContainer->get(UltraNormalizer::class);
        $runtime->registerRuntimeNormalizer($runtimeContainer->get('email_normalizer'));
        $customer = new CustomerWithRuntimeEmail('Ada', new RuntimeEmail('ada@example.test'), '123');
        $plainRepresentation = ['name' => 'Ada', 'email' => ['value' => 'ada@example.test'], 'phone' => '123'];
        $runtimeRepresentation = ['name' => 'Ada', 'email' => 'runtime-email:ada@example.test', 'phone' => '123'];

        self::assertSame($plainRepresentation, $plain->toScalar($customer));
        self::assertSame($runtimeRepresentation, $runtime->toScalar($customer));
        self::assertEquals($customer, $plain->fromScalar($plainRepresentation, CustomerWithRuntimeEmail::class));
        self::assertEquals($customer, $runtime->fromScalar($runtimeRepresentation, CustomerWithRuntimeEmail::class));
        self::assertSame($plainRepresentation, $plain->toScalar($customer));
        self::assertSame($compiled, $this->generatedFiles($cache));
    }

    public function test_precompiled_artifacts_can_move_without_changing_their_generation_namespace(): void
    {
        $namespace = 'Ultra\\Mapper\\Tests\\Generated\\Moved'.bin2hex(random_bytes(8));
        $cache = $this->directory.'/compiled';
        $container = new ContainerBuilder();
        $container->setDefinition('record', new Definition(\stdClass::class)
            ->addResourceTag(TagNames::NORMALIZABLE, ['class' => Foo::class]));
        new MapperCompilerPass($cache, generationNamespace: $namespace)->process($container);
        $files = $this->generatedFiles($cache);
        self::assertCount(1, $files);
        $movedCache = $this->directory.'/deployed';
        rename($cache, $movedCache);
        $compiled = $this->generatedFiles($movedCache);
        $includedBefore = get_included_files();
        $normalizer = new UltraNormalizer($movedCache, generationNamespace: $namespace);

        self::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
        self::assertEquals(new Foo(42, 'bar'), $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class));
        $loaded = array_diff(get_included_files(), $includedBefore);
        foreach (array_keys($compiled) as $file) {
            self::assertContains($file, $loaded);
        }
    }

    private function container(string $generationNamespace, bool $withRuntimeNormalizer): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', $this->directory.'/build');
        new MapperExtension(generationNamespace: $generationNamespace)->build($container);
        $container->getDefinition(UltraNormalizer::class)->setPublic(true);
        $container->setDefinition('record', new Definition(\stdClass::class)
            ->addResourceTag(TagNames::NORMALIZABLE, ['class' => CustomerWithRuntimeEmail::class]));
        if ($withRuntimeNormalizer) {
            $container->setDefinition('email_normalizer', new Definition(RuntimeEmailNormalizer::class)
                ->setAutoconfigured(true)->setPublic(true));
        }

        return $container;
    }
}
