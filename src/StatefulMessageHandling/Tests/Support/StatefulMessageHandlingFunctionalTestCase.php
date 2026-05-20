<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Events\CompiledEventRegistry;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainerFactoryInterface;

abstract class StatefulMessageHandlingFunctionalTestCase extends TestCase
{
    /**
     * @param list<class-string> $eventClasses
     *
     * @return UltraLight<StatefulMessageHandlingTestContainer>
     */
    protected function decisionUltraLight(array $eventClasses): UltraLight
    {
        $buildDir = dirname(__DIR__, 4).'/var/stateful_message_handling_compiler_tests';
        $eventBus = new class implements EventBusInterface {
            public function publish(object $event, array|MessageContext $context = []): void {}
        };

        /** @var UltraLightContainerFactoryInterface<StatefulMessageHandlingTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): StatefulMessageHandlingTestContainer
            {
                return new StatefulMessageHandlingTestContainer($container);
            }
        };

        return new UltraLight()
            ->withContainerFactory($containerFactory)
            ->withBuildDir($buildDir)
            ->withBundle(new MessageHandlingExtension())
            ->withBundle(new EventSourcedStateExtension())
            ->withBundle(new StatefulMessageHandlingExtension())
            ->withBundle(new MapperExtension())
            ->withService(new InMemoryEventStore(), InMemoryEventStore::class)
            ->withAlias(EventStore::class, InMemoryEventStore::class)
            ->withAlias('test.mediator', MediatorInterface::class)
            ->withService($eventBus, EventBusInterface::class)
            ->withService(new CompiledEventRegistry(
                self::eventClassMap($eventClasses),
                self::eventDomainIdentifierMap($eventClasses),
            ), EventRegistryInterface::class)
        ;
    }

    /**
     * @param list<class-string> $eventClasses
     *
     * @return array<string, class-string>
     */
    private static function eventClassMap(array $eventClasses): array
    {
        $map = [];

        foreach ($eventClasses as $eventClass) {
            $map[self::eventName($eventClass)] = $eventClass;
        }

        return $map;
    }

    /**
     * @param list<class-string> $eventClasses
     *
     * @return array<class-string, array<string, string>>
     */
    private static function eventDomainIdentifierMap(array $eventClasses): array
    {
        $map = [];

        foreach ($eventClasses as $eventClass) {
            $map[$eventClass] = self::eventDomainIdentifiers($eventClass);
        }

        return $map;
    }

    /**
     * @param class-string $eventClass
     */
    private static function eventName(string $eventClass): string
    {
        $reflection = new \ReflectionClass($eventClass);
        $attributes = $reflection->getAttributes(EventAttribute::class);

        if ([] === $attributes) {
            throw new \LogicException(sprintf('Event class %s is missing #[Event].', $eventClass));
        }

        /** @var EventAttribute $attribute */
        $attribute = $attributes[0]->newInstance();

        return $attribute->name;
    }

    /**
     * @param class-string $eventClass
     *
     * @return array<string, string>
     */
    private static function eventDomainIdentifiers(string $eventClass): array
    {
        $reflection = new \ReflectionClass($eventClass);
        $identifiers = [];

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifierAttribute::class) as $attribute) {
                /** @var DomainIdentifierAttribute $domainIdentifier */
                $domainIdentifier = $attribute->newInstance();
                $identifiers[$domainIdentifier->name] = $property->getName();
            }
        }

        return $identifiers;
    }
}
