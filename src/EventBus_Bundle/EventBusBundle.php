<?php

declare(strict_types=1);

namespace Ultra\EventBus_Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\EventBus\Attribute\EventHandler;
use Ultra\EventBus_Compiler\EventBusCompilerPass;
use Ultra\Events\Attribute\Event;
use Ultra\MessageHandling\Attribute\Advice;

final class EventBusBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (! $container->hasParameter('ultra_event_bus.generated_class')) {
            $container->setParameter('ultra_event_bus.generated_class', 'CompiledEventBus');
        }

        $container->registerAttributeForAutoconfiguration(
            EventHandler::class,
            static function (ChildDefinition $definition, EventHandler $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::HANDLER, ['method' => $reflector->getName()]);
                }
            }
        );

        $container->registerAttributeForAutoconfiguration(
            Advice::class,
            static function (ChildDefinition $definition, Advice $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionMethod) {
                    $definition->addTag(TagNames::ASPECT);
                }
            }
        );

        $buildDir = (string) $container->getParameter('kernel.build_dir');
        $outputDir = $buildDir.'/event_bus';
        $generatedClass = (string) $container->getParameter('ultra_event_bus.generated_class');

        $container->addCompilerPass(new EventBusCompilerPass(
            $outputDir,
            $generatedClass,
            TagNames::HANDLER,
            TagNames::ASPECT,
            TagNames::EVENT,
        ));
    }
}
