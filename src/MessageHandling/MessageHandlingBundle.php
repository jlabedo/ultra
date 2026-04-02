<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

final class MessageHandlingBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            Advice::class,
            static function (ChildDefinition $definition, Advice $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::ASPECT);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            EventHandler::class,
            static function (ChildDefinition $definition, EventHandler $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::HANDLER);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            Handles::class,
            static function (ChildDefinition $definition, Handles $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::HANDLER);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            SelfHandler::class,
            static function (ChildDefinition $definition, SelfHandler $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::HANDLER);
                }
            }
        );

        $container->register(MediatorInterface::class)
            ->addTag(TagNames::ROUTER, ['interface' => MediatorInterface::class])
            ->setAbstract(true)
        ;

        $container->register(EventBusInterface::class)
            ->addTag(TagNames::ROUTER, ['interface' => EventBusInterface::class])
            ->setAbstract(true)
        ;

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir.'/message_handling';

        $container->addCompilerPass(new MessageRouterCompilerPass($outputDir));
    }
}
