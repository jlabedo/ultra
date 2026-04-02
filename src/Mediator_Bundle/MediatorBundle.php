<?php

declare(strict_types=1);

namespace Ultra\Mediator_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Mediator\Attribute\Handles;
use Ultra\Mediator\Attribute\SelfHandler;
use Ultra\Mediator\MediatorInterface;
use Ultra\MessageHandling_Bundle\TagNames as MessageHandlingTagNames;

final class MediatorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            Handles::class,
            static function (ChildDefinition $definition, Handles $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(MessageHandlingTagNames::HANDLER);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            SelfHandler::class,
            static function (ChildDefinition $definition, SelfHandler $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(MessageHandlingTagNames::HANDLER);
                }
            }
        );

        // Register MediatorInterface as a router so MessageRouterCompilerPass can discover it
        $container->setDefinition(
            MediatorInterface::class,
            (new Definition(MediatorInterface::class))
                ->addTag(MessageHandlingTagNames::ROUTER, ['interface' => MediatorInterface::class])
                ->setAbstract(true),
        );
    }
}
