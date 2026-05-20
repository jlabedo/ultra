<?php

declare(strict_types=1);

namespace Ultra\MessageHandling;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Container\ContainerExtension;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling_Compiler\MessageRouterCompilerPass;

final class MessageHandlingExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {

        $container->registerAttributeForAutoconfiguration(
            Advice::class,
            static function (ChildDefinition $definition, Advice $attribute, \ReflectionMethod $reflector): void {
                $definition->addTag(TagNames::ASPECT);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            EventHandler::class,
            static function (ChildDefinition $definition, EventHandler $attribute, \ReflectionMethod $reflector): void {
                $definition->addTag(TagNames::HANDLER, ['method' => $reflector->getName()]);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            Handles::class,
            static function (ChildDefinition $definition, Handles $attribute, \ReflectionMethod $reflector): void {
                $definition->addTag(TagNames::HANDLER, ['method' => $reflector->getName()]);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            SelfHandler::class,
            static function (ChildDefinition $definition, SelfHandler $attribute, \ReflectionMethod $reflector): void {
                $definition->addResourceTag(TagNames::SELF_HANDLER, ['method' => $reflector->getName()]);
            }
        );

        $this->ensureRouterDefinition($container, MediatorInterface::class);
        $this->ensureRouterDefinition($container, EventBusInterface::class);

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir.'/message_handling';

        $container->addCompilerPass(new MessageRouterCompilerPass($outputDir));
    }

    /**
     * Ensure a router interface has a definition with the ROUTER tag.
     *
     * If the definition already exists (e.g. a synthetic test double), the ROUTER
     * tag is added to enable wrapper generation for that interface. If it doesn't
     * exist, a new abstract definition is created.
     */
    private function ensureRouterDefinition(ContainerBuilder $container, string $interfaceClass): void
    {
        if ($container->hasDefinition($interfaceClass)) {
            $definition = $container->getDefinition($interfaceClass);
            if (!$definition->hasTag(TagNames::ROUTER)) {
                $definition->addTag(TagNames::ROUTER, ['interface' => $interfaceClass]);
            }

            return;
        }

        $container->register($interfaceClass)
            ->addTag(TagNames::ROUTER, ['interface' => $interfaceClass])
            ->setAbstract(true)
        ;
    }
}
