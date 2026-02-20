<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Ultra\DecisionEngine\Engine\CommandMetadata;
use Ultra\DecisionEngine\Engine\CommandMetadataResolver;
use Ultra\DecisionEngine\Engine\EventClassRegistry;
use Ultra\DecisionEngine\Engine\EventRouter;
use Ultra\DecisionEngine\Engine\StateParameterMetadata;
use Ultra\DecisionEngine\Engine\StreamIdentityResolver;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StoredEvent;
use Psr\Container\ContainerInterface;

final class Engine
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly CommandMetadataResolver $metadataResolver,
        private readonly StreamIdentityResolver $streamIdentityResolver,
        private readonly EventRouter $eventRouter,
        private readonly EventClassRegistry $eventClassRegistry,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Execute the command: load states, call decide, append events. Returns the emitted event DTOs.
     *
     * @return list<object>
     */
    public function handle(object $command): array
    {
        $metadata = $this->metadataResolver->resolve($command::class);
        $query = $this->streamIdentityResolver->resolveStreamQuery($command, $metadata);
        $loadResult = $this->eventStore->load($query);

        $states = [];
        foreach ($metadata->stateParameters as $stateParam) {
            $streamValue = $this->getCommandPropertyValue($command, $stateParam->commandProperty);
            $streamEvents = $this->eventsForStream($loadResult->events, $stateParam, $streamValue);

            if ($streamEvents === []) {
                if ($stateParam->isNullable) {
                    $states[] = null;
                } else {
                    throw new Exception\StreamNotFoundException(
                        $stateParam->domainIdentifierName,
                        $streamValue,
                    );
                }
            } else {
                $state = $stateParam->stateClass::fromEvents($streamEvents);
                $states[] = $state;
            }
        }

        $args = $this->buildDecideArguments($metadata, $states);
        $emittedEvents = $metadata->decideMethod->invoke($command, ...$args);

        if (!is_array($emittedEvents)) {
            $emittedEvents = [];
        }

        $appendableEvents = $this->eventRouter->toAppendableEvents($emittedEvents);

        $this->eventStore->append($appendableEvents, $loadResult->streamVersion);

        return $emittedEvents;
    }

    /**
     * Run check only (read-only). No append. Returns violations.
     *
     * @return list<Violation>
     */
    public function check(object $command): array
    {
        $metadata = $this->metadataResolver->resolve($command::class);
        if ($metadata->checkMethod === null) {
            throw new Exception\CommandHasNoCheckException($command::class);
        }

        $query = $this->streamIdentityResolver->resolveStreamQuery($command, $metadata);
        $loadResult = $this->eventStore->load($query);

        $states = [];
        foreach ($metadata->stateParameters as $stateParam) {
            $streamValue = $this->getCommandPropertyValue($command, $stateParam->commandProperty);
            $streamEvents = $this->eventsForStream($loadResult->events, $stateParam, $streamValue);

            if ($streamEvents === [] && !$stateParam->isNullable) {
                throw new Exception\StreamNotFoundException(
                    $stateParam->domainIdentifierName,
                    $streamValue,
                );
            }

            if ($streamEvents === []) {
                $states[] = null;
            } else {
                $states[] = $stateParam->stateClass::fromEvents($streamEvents);
            }
        }

        $checkArgs = $this->buildCheckArguments($metadata, $states);
        $violations = $metadata->checkMethod->invoke($command, ...$checkArgs);

        return is_array($violations) ? $violations : [];
    }

    /**
     * Filter loaded events to those belonging to the given stream and convert to DTOs for the state.
     *
     * @param list<StoredEvent> $events
     * @return list<object>
     */
    private function eventsForStream(array $events, StateParameterMetadata $stateParam, string $streamValue): array
    {
        $dimension = $stateParam->domainIdentifierName;
        $filtered = [];
        foreach ($events as $stored) {
            if (($stored->domainIds[$dimension] ?? null) !== $streamValue) {
                continue;
            }
            $filtered[] = $stored;
        }

        return $this->storedEventsToObjects($filtered, $stateParam->stateClass);
    }

    /**
     * @param list<StoredEvent> $events
     * @return list<object>
     */
    private function storedEventsToObjects(array $events, string $stateClass): array
    {
        $handledTypes = $stateClass::handledEventTypes();
        $objects = [];
        foreach ($events as $stored) {
            $eventClass = $this->eventTypeToClass($stored->eventType);
            if ($eventClass === null || !in_array($eventClass, $handledTypes, true)) {
                continue;
            }
            $objects[] = $this->storedEventToDto($stored, $eventClass);
        }

        return $objects;
    }

    private function eventTypeToClass(string $eventType): ?string
    {
        return $this->eventClassRegistry->getClass($eventType);
    }

    private function storedEventToDto(StoredEvent $stored, string $eventClass): object
    {
        $payload = $stored->payload;
        $ref = new \ReflectionClass($eventClass);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }
        $params = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();
            $params[] = $payload[$name] ?? throw new \LogicException(sprintf('Missing payload key "%s" for event %s', $name, $eventClass));
        }

        return $ref->newInstance(...$params);
    }

    /**
     * @param list<object|null> $states
     * @return list<mixed>
     */
    private function buildDecideArguments(CommandMetadata $metadata, array $states): array
    {
        $stateIndex = 0;
        $serviceIndex = 0;
        $args = [];
        foreach ($metadata->decideMethod->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType) {
                $args[] = null;
                continue;
            }
            $class = $type->getName();
            if ($this->isStateClass($class)) {
                $args[] = $states[$stateIndex++] ?? null;
            } else {
                $serviceParam = $metadata->serviceParameters[$serviceIndex++] ?? null;
                $args[] = $serviceParam !== null
                    ? $this->container->get($serviceParam->serviceClass)
                    : $this->container->get($class);
            }
        }

        return $args;
    }

    /**
     * @param list<object|null> $states
     * @return list<mixed>
     */
    private function buildCheckArguments(CommandMetadata $metadata, array $states): array
    {
        if ($metadata->checkMethod === null) {
            return [];
        }
        $stateByName = [];
        foreach ($metadata->stateParameters as $i => $p) {
            $stateByName[$p->parameterName] = $states[$i];
        }
        $args = [];
        foreach ($metadata->checkMethod->getParameters() as $param) {
            $args[] = $stateByName[$param->getName()] ?? null;
        }

        return $args;
    }

    private function getCommandPropertyValue(object $command, string $property): string
    {
        $ref = new \ReflectionClass($command);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);

        return (string) $prop->getValue($command);
    }

    private function isStateClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $ref = new \ReflectionClass($class);
        if ($ref->getTraitNames() !== [] && in_array(ReducerTrait::class, $ref->getTraitNames(), true)) {
            return true;
        }
        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(\Ultra\DecisionEngine\Attribute\DomainIdentifier::class) as $attr) {
                return true;
            }
        }

        return false;
    }
}
