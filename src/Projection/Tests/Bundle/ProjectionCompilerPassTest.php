<?php

declare(strict_types=1);

namespace Ultra\Projection\Tests\Bundle;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Attribute\Projection;
use Ultra\Projection\Attribute\ProjectionHandler;
use Ultra\Projection\Bundle\DependencyInjection\Compiler\ProjectionCompilerPass;
use Ultra\Projection\Bundle\TagNames;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;

#[Projection(name: 'stub_projection', partitionBy: 'id')]
final class StubProjection
{
    #[ProjectionHandler]
    public function onSomething(object $event): void {}
}

#[CoversClass(ProjectionCompilerPass::class)]
final class ProjectionCompilerPassTest extends TestCase
{
    public function test_registers_empty_catalog_when_no_projections_are_tagged(): void
    {
        $container = new ContainerBuilder();
        $container->register(UltraNormalizer::class, UltraNormalizer::class);

        (new ProjectionCompilerPass())->process($container);

        self::assertTrue($container->hasDefinition(ProjectionCatalog::class));
        self::assertCount(0, $container->getDefinition(ProjectionCatalog::class)->getArguments());
    }

    public function test_wraps_each_tagged_projection_in_a_runtime(): void
    {
        $container = new ContainerBuilder();
        $container->register(UltraNormalizer::class, UltraNormalizer::class);

        $def = new Definition(StubProjection::class);
        $def->addTag(TagNames::PROJECTION);
        $container->setDefinition('stub_projection', $def);

        (new ProjectionCompilerPass())->process($container);

        $runtimeId = 'ultra.projection.runtime.stub_projection';
        self::assertTrue($container->hasDefinition($runtimeId));

        $runtime = $container->getDefinition($runtimeId);
        self::assertSame(ProjectionRuntime::class, $runtime->getClass());

        $args = $runtime->getArguments();
        self::assertInstanceOf(Reference::class, $args[0]);
        self::assertSame('stub_projection', (string) $args[0]);
        self::assertInstanceOf(Reference::class, $args[1]);
        self::assertSame(UltraNormalizer::class, (string) $args[1]);
    }

    public function test_catalog_receives_one_runtime_per_tagged_projection(): void
    {
        $container = new ContainerBuilder();
        $container->register(UltraNormalizer::class, UltraNormalizer::class);

        foreach (['proj_a', 'proj_b', 'proj_c'] as $id) {
            $def = new Definition(StubProjection::class);
            $def->addTag(TagNames::PROJECTION);
            $container->setDefinition($id, $def);
        }

        (new ProjectionCompilerPass())->process($container);

        $catalogArgs = $container->getDefinition(ProjectionCatalog::class)->getArguments();
        self::assertCount(3, $catalogArgs);

        foreach ($catalogArgs as $arg) {
            self::assertInstanceOf(Reference::class, $arg);
            self::assertStringStartsWith('ultra.projection.runtime.', (string) $arg);
        }
    }
}
