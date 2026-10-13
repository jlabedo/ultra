<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Projection\Coordination\DefaultProjectionScalingPolicy;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\Coordination\ProjectionScalingPolicyInterface;
use Ultra\Projection\InMemory\InMemoryProjectionActivator;
use Ultra\Projection\MessageHandling\MessageHandlingProjectionActivator;

final class ProjectionDefaultsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ProjectionScalingPolicyInterface::class)) {
            $container->register(ProjectionScalingPolicyInterface::class, DefaultProjectionScalingPolicy::class);
        }

        if ($container->has(ProjectionActivatorInterface::class)) {
            return;
        }

        $mediator = 'Ultra\MessageHandling\MediatorInterface';
        if (!$container->has($mediator)) {
            $container->register(ProjectionActivatorInterface::class, InMemoryProjectionActivator::class);

            return;
        }

        $container->setDefinition(ProjectionActivatorInterface::class, new Definition(MessageHandlingProjectionActivator::class, [
            new Reference($mediator),
        ]));
    }
}
