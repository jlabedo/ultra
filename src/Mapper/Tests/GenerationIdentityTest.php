<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests;

use PHPUnit\Framework\Attributes\CoversNamespace;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
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
        foreach ($files as $file => $code) {
            file_put_contents($file, $code."\n\$GLOBALS['mapper_moved_artifacts'][] = __FILE__;\n");
        }
        $movedCache = $this->directory.'/deployed';
        rename($cache, $movedCache);
        $compiled = $this->generatedFiles($movedCache);
        $GLOBALS['mapper_moved_artifacts'] = [];
        try {
            $normalizer = new UltraNormalizer($movedCache, generationNamespace: $namespace);

            self::assertSame(['id' => 42, 'name' => 'bar'], $normalizer->toScalar(new Foo(42, 'bar')));
            self::assertEquals(new Foo(42, 'bar'), $normalizer->fromScalar(['id' => 42, 'name' => 'bar'], Foo::class));
            self::assertSame(array_keys($compiled), $GLOBALS['mapper_moved_artifacts']);
            self::assertSame($compiled, $this->generatedFiles($movedCache));
        } finally {
            unset($GLOBALS['mapper_moved_artifacts']);
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
