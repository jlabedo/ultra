<?php

declare(strict_types=1);

namespace Ultra\Events;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class EventRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $eventMap = [];
        foreach ($container->findTaggedResourceIds(EventsBundle::EVENT_TAG) as $serviceId => $tags) {
            $class = $container->getDefinition($serviceId)->getClass();
            foreach ($tags as $tag) {
                $name = $tag['name'] ?? $class;
                if (isset($eventMap[$name]) && $eventMap[$name] !== $class) {
                    throw new \LogicException(sprintf(
                        'Duplicate #[Event] name "%s": "%s" and "%s".',
                        $name,
                        $eventMap[$name],
                        $class,
                    ));
                }
                $eventMap[$name] = $class;
            }
        }

        $container->setDefinition(EventRegistryInterface::class, new Definition(
            CompiledEventRegistry::class,
            [$eventMap]
        ));
    }
}
