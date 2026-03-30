<?php

declare(strict_types=1);

namespace Ultra\Events;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\Attribute\Event;

final class EventsBundle extends Bundle
{
    public const string EVENT_TAG = 'ultra_events.event';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerAttributeForAutoconfiguration(
            Event::class,
            static function (ChildDefinition $definition, Event $attribute, \Reflector $reflector): void {
                if ($reflector instanceof \ReflectionClass) {
                    $tags = [];
                    foreach ($reflector->getProperties() as $property) {
                        foreach ($property->getAttributes(DomainIdentifier::class) as $attribute) {
                            $tags[$attribute->newInstance()->name] = $property->getName();
                        }
                    }
                    $definition->addTag('ultra_event_bus.event');
                    $definition->addTag('ultra_mapper.normalizable');
                    $definition->addResourceTag(self::EVENT_TAG, ['name' => $attribute->name, 'tags' => $tags]);
                }
            }
        );

        $container->addCompilerPass(new EventRegistryCompilerPass());
    }
}
