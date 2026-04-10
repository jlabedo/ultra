<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Tests\Support;

use PHPUnit\Framework\TestCase;
use Ultra\DecisionEngine\DecisionEngineBundle;
use Ultra\Events\Attribute\DomainIdentifier as DomainIdentifierAttribute;
use Ultra\Events\Attribute\Event as EventAttribute;
use Ultra\Events\CompiledEventRegistry;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventTopology;
use Ultra\EventStore\EventTopologyProvider;
use Ultra\EventStore\InMemoryEventStore;
use Ultra\EventStore\StaticEventTopologyProvider;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling_Compiler\Tests\Support\UltraLight;

abstract class DecisionEngineFunctionalTestCase extends TestCase
{
    /**
     * @param list<class-string> $eventClasses
     */
    protected function decisionUltraLight(array $eventClasses, ?EventBusInterface $eventBus = null): UltraLight
    {
        $buildDir = dirname(__DIR__, 4) . '/var/decision_engine_compiler_tests';
        $topologyProvider = new StaticEventTopologyProvider(self::eventTopologyMap($eventClasses));
        $eventBus ??= new class implements EventBusInterface {
            public function publish(object $event, array|MessageContext $context = []): void {}
        };

        return (new UltraLight())
            ->withBuildDir($buildDir)
            ->withBundle(new DecisionEngineBundle())
            ->withService(new UltraNormalizer($buildDir . '/normalizer'), UltraNormalizer::class)
            ->withService($topologyProvider, EventTopologyProvider::class)
            ->withService(new InMemoryEventStore($topologyProvider), InMemoryEventStore::class)
            ->withAlias(EventStore::class, InMemoryEventStore::class)
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
     * @param list<class-string> $eventClasses
     *
     * @return array<string, EventTopology>
     */
    private static function eventTopologyMap(array $eventClasses): array
    {
        $map = [];

        foreach ($eventClasses as $eventClass) {
            $map[self::eventName($eventClass)] = new EventTopology(
                array_keys(self::eventDomainIdentifiers($eventClass)),
            );
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
