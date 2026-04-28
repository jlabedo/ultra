<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Projection\Coordination\ProjectionActivatorInterface;
use Ultra\Projection\MessageHandling\MessageHandlingProjectionActivator;

final class MessageHandlingProjectionActivatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $mediator = 'Ultra\\MessageHandling\\MediatorInterface';
        if (!$container->hasDefinition($mediator) && !$container->hasAlias($mediator)) {
            return;
        }

        $container->setDefinition(
            ProjectionActivatorInterface::class,
            new Definition(MessageHandlingProjectionActivator::class, [
                new Reference($mediator),
            ]),
        );
    }
}
