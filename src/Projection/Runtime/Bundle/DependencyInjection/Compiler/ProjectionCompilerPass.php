<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Mapper\UltraNormalizer;
use Ultra\Projection\Bundle\TagNames;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRuntime;

final class ProjectionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $runtimes = [];

        foreach (array_keys($container->findTaggedServiceIds(TagNames::PROJECTION)) as $serviceId) {
            $runtime = new Definition(ProjectionRuntime::class, [
                new Reference($serviceId),
                new Reference(UltraNormalizer::class),
            ]);

            $runtimeId = 'ultra.projection.runtime.' . $serviceId;
            $container->setDefinition($runtimeId, $runtime);

            $runtimes[] = new Reference($runtimeId);
        }

        $container->register(ProjectionCatalog::class, ProjectionCatalog::class)->setArguments($runtimes);
    }
}
