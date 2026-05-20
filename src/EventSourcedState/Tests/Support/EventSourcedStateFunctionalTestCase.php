<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Events\CompiledEventRegistry;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\Testing\UltraLight;
use Ultra\Testing\UltraLightContainerFactoryInterface;

abstract class EventSourcedStateFunctionalTestCase extends TestCase
{
    /**
     * @param list<class-string> $eventClasses
     *
     * @return UltraLight<EventSourcedStateTestContainer>
     */
    protected function eventSourcedStateUltraLight(array $eventClasses): UltraLight
    {
        $buildDir = dirname(__DIR__, 4).'/var/event_sourced_state_compiler_tests';

        /** @var UltraLightContainerFactoryInterface<EventSourcedStateTestContainer> $containerFactory */
        $containerFactory = new class implements UltraLightContainerFactoryInterface {
            public function create(Container $container): EventSourcedStateTestContainer
            {
                return new EventSourcedStateTestContainer($container);
            }
        };

        return new UltraLight()
            ->withContainerFactory($containerFactory)
            ->withBuildDir($buildDir)
            ->withBundle(new EventSourcedStateExtension())
            ->withBundle(new MapperExtension())
            ->withService(new InMemoryEventStore(), InMemoryEventStore::class)
            ->withAlias(EventStore::class, InMemoryEventStore::class)
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
