<?php

declare(strict_types=1);

namespace Ultra\EventBus_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\TagNames as MessageHandlingTagNames;

final class EventBusBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            EventHandler::class,
            static function (ChildDefinition $definition, EventHandler $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(MessageHandlingTagNames::HANDLER);
                }
            }
        );

        // Register EventBusInterface as a router so MessageRouterCompilerPass can discover it
        $container->setDefinition(
            EventBusInterface::class,
            (new Definition(EventBusInterface::class))
                ->addTag(MessageHandlingTagNames::ROUTER, ['interface' => EventBusInterface::class])
                ->setAbstract(true),
        );
    }
}
