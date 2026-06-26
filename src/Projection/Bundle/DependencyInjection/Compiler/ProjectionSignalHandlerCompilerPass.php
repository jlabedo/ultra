<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Projection\Bundle\TagNames;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\Signal\ProjectionSignalHandler;
use Ultra\Signal\SignalStore;

final readonly class ProjectionSignalHandlerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(SignalStore::class) || !$container->has(SignalStore::class)) {
            return;
        }

        foreach (array_keys($container->findTaggedServiceIds(TagNames::PROJECTION)) as $serviceId) {
            $runtimeId = 'ultra.projection.runtime.'.$serviceId;
            if (!$container->hasDefinition($runtimeId)) {
                continue;
            }

            $handlerId = 'ultra.projection.signal_handler.'.$serviceId;
            $container->setDefinition($handlerId, (new Definition(ProjectionSignalHandler::class))
                ->setArguments([
                    new Reference($runtimeId),
                    new Reference(ProjectionRunner::class),
                    new Reference(SignalStore::class),
                    '%ultra.projection.signal_worker_id%',
                ])
                ->addTag(TagNames::SIGNAL_HANDLER)
            );
        }
    }
}
