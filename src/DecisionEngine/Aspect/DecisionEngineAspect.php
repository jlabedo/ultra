<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Aspect;

use Ultra\DecisionEngine\Attribute\BindTo;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Exception\CommandHasNoCheckException;
use Ultra\DecisionEngine\Exception\StreamNotFoundException;
use Ultra\DecisionEngine\ReducerTrait;
use Ultra\EventBus\EventBusInterface;
use Ultra\Events\Attribute\DomainIdentifier;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\AppendableEvent;
use Ultra\EventStore\DomainIdentifier as EventStoreDomainIdentifier;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StoredEvent;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;

final class DecisionEngineAspect
{
    private const CONTEXT_DECISION_MODE = 'decision_mode';
    private const MODE_CHECK = 'check';

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly EventRegistryInterface $eventClassRegistry,
        private readonly UltraNormalizer $normalizer,
        private readonly ?EventBusInterface $eventBus = null,
    ) {}

    #[Around(static function (\ReflectionMethod $method): ?MatchResult {
        if ([] === $method->getAttributes(DecisionHandler::class)) {
            return null;
        }

        $commandClass = $method->getDeclaringClass()->getName();
        $commandRef = new \ReflectionClass($commandClass);

        $stateParams = [];
        $provides = [];

        foreach ($method->getParameters() as $index => $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeClass = $type->getName();
            if (! DecisionEngineAspect::isStateClass($typeClass)) {
                continue;
            }

            $domainIdentifierName = DecisionEngineAspect::getDomainIdentifierName($typeClass);
            $bindTo = null;
            foreach ($parameter->getAttributes(BindTo::class) as $attr) {
                $bindTo = $attr->newInstance();

                break;
            }
            $commandProperty = null !== $bindTo
                ? $bindTo->property
                : $domainIdentifierName . 'Id';
            if (! $commandRef->hasProperty($commandProperty)) {
                throw new \LogicException(sprintf(
                    'Command %s has no property "%s" required for decision handler parameter "%s" (domain identifier "%s"). '
                        . 'Either add the property or use #[BindTo("propertyName")] to bind to a different command property.',
                    $commandClass,
                    $commandProperty,
                    $parameter->getName(),
                    $domainIdentifierName,
                ));
            }

            $stateParams[] = [
                'parameterName' => $parameter->getName(),
                'stateClass' => $typeClass,
                'domainIdentifierName' => $domainIdentifierName,
                'commandProperty' => $commandProperty,
                'isNullable' => $type->allowsNull(),
            ];
            $provides[] = $index;
        }

        $checkParamNames = null;
        if ($commandRef->hasMethod('check')) {
            $checkMethod = $commandRef->getMethod('check');
            $checkParamNames = [];
            foreach ($checkMethod->getParameters() as $param) {
                $checkParamNames[] = $param->getName();
            }
        }

        return MatchResult::apply(
            config: [
                'stateParams' => $stateParams,
                'checkParamNames' => $checkParamNames,
            ],
            provides: $provides,
        );
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): mixed
    {
        $stateParams = $config['stateParams'] ?? [];
        $loadResult = $this->eventStore->load($this->buildStreamQuery($message, $stateParams));

        $states = [];
        $stateByName = [];

        foreach ($stateParams as $stateParam) {
            $streamValue = $this->getCommandPropertyValue($message, $stateParam['commandProperty']);
            $streamEvents = $this->eventsForStream($loadResult->events, $stateParam, $streamValue);

            if ([] === $streamEvents) {
                if ($stateParam['isNullable']) {
                    $state = null;
                } else {
                    throw new StreamNotFoundException(
                        $stateParam['domainIdentifierName'],
                        $streamValue,
                    );
                }
            } else {
                $state = $stateParam['stateClass']::fromEvents($streamEvents);
            }

            $states[] = $state;
            $stateByName[$stateParam['parameterName']] = $state;
        }

        if (self::MODE_CHECK === $context->get(self::CONTEXT_DECISION_MODE)) {
            $checkParamNames = $config['checkParamNames'] ?? null;
            if (null === $checkParamNames) {
                throw new CommandHasNoCheckException($message::class);
            }
            $checkArgs = [];
            foreach ($checkParamNames as $paramName) {
                $checkArgs[] = $stateByName[$paramName] ?? null;
            }
            $violations = $message->check(...$checkArgs);

            return is_array($violations) ? $violations : [];
        }

        $emittedEvents = $proceed(provided: $states);
        if (! is_array($emittedEvents)) {
            $emittedEvents = [];
        }

        $appendableEvents = $this->toAppendableEvents($emittedEvents);
        $this->eventStore->append($appendableEvents, $loadResult->queryVersion);
        foreach ($emittedEvents as $emittedEvent) {
            $this->eventBus?->publish($emittedEvent, $context);
        }

        return $emittedEvents;
    }

    /**
     * @param list<array<string, mixed>> $stateParams
     */
    private function buildStreamQuery(object $command, array $stateParams): StreamQuery
    {
        $branches = [];
        foreach ($stateParams as $stateParam) {
            $value = $this->getCommandPropertyValue($command, $stateParam['commandProperty']);
            $branches[] = StreamQuery::forStream(new EventStoreDomainIdentifier(
                $stateParam['domainIdentifierName'],
                $value,
            ))->ofTypes(...$this->handledEventNames($stateParam['stateClass']));
        }

        return StreamQuery::anyOf(...$branches);
    }

    /**
     * @param list<StoredEvent>    $events
     * @param array<string, mixed> $stateParam
     *
     * @return list<object>
     */
    private function eventsForStream(array $events, array $stateParam, string $streamValue): array
    {
        $dimension = $stateParam['domainIdentifierName'];
        $filtered = [];
        foreach ($events as $stored) {
            if (($stored->domainIds[$dimension] ?? null) !== $streamValue) {
                continue;
            }
            $filtered[] = $stored;
        }

        return $this->storedEventsToObjects($filtered, $stateParam['stateClass']);
    }

    /**
     * @param list<StoredEvent> $events
     *
     * @return list<object>
     */
    private function storedEventsToObjects(array $events, string $stateClass): array
    {
        $handledTypes = $stateClass::handledEventTypes();
        $objects = [];
        foreach ($events as $stored) {
            $eventClass = $this->eventClassRegistry->getClass($stored->eventType);
            if (null === $eventClass || ! in_array($eventClass, $handledTypes, true)) {
                continue;
            }
            $objects[] = $this->storedEventToDto($stored, $eventClass);
        }

        return $objects;
    }

    private function storedEventToDto(StoredEvent $stored, string $eventClass): object
    {
        return $this->normalizer->fromScalar($stored->payload, $eventClass);
    }

    /**
     * @param list<object> $events
     *
     * @return list<AppendableEvent>
     */
    private function toAppendableEvents(array $events): array
    {
        return array_map(
            $this->toAppendableEvent(...),
            $events,
        );
    }

    private function toAppendableEvent(object $event): AppendableEvent
    {
        $eventType = $this->eventClassRegistry->getName($event::class);
        if (null === $eventType) {
            throw new \LogicException(sprintf('Event class %s has no #[Event] attribute.', $event::class));
        }

        $domainIds = $this->eventClassRegistry->getDomainIdentifiers($event);
        if ([] === $domainIds) {
            throw new \LogicException(sprintf('Event class %s has no properties with #[DomainIdentifier].', $event::class));
        }

        $payload = $this->normalizer->toScalar($event);
        if (! is_array($payload)) {
            throw new \LogicException(sprintf('Event %s normalized payload must be an array, got %s.', $event::class, get_debug_type($payload)));
        }

        return new AppendableEvent(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
        );
    }

    /**
     * @return list<string>
     */
    private function handledEventNames(string $stateClass): array
    {
        $names = [];
        foreach ($stateClass::handledEventTypes() as $eventClass) {
            $eventName = $this->eventClassRegistry->getName($eventClass);
            if (null === $eventName) {
                throw new \LogicException(sprintf(
                    'State class %s handles event class %s but it is not registered.',
                    $stateClass,
                    $eventClass,
                ));
            }
            $names[] = $eventName;
        }

        return array_values(array_unique($names));
    }

    private function getCommandPropertyValue(object $command, string $property): string
    {
        $getter = function (string $prop): mixed {
            return $this->{$prop};
        };
        $value = $getter->call($command, $property);
        if (! is_string($value)) {
            throw new \LogicException(sprintf('Command property "%s" must be string, got %s.', $property, get_debug_type($value)));
        }

        return $value;
    }

    private static function isStateClass(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }
        $ref = new \ReflectionClass($class);

        if ([] !== $ref->getTraitNames() && in_array(ReducerTrait::class, $ref->getTraitNames(), true)) {
            return true;
        }

        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attr) {
                return true;
            }
        }

        return false;
    }

    private static function getDomainIdentifierName(string $stateClass): string
    {
        $ref = new \ReflectionClass($stateClass);
        foreach ($ref->getProperties() as $property) {
            foreach ($property->getAttributes(DomainIdentifier::class) as $attr) {
                return $attr->newInstance()->name;
            }
        }

        throw new \LogicException(sprintf(
            'State class %s has no property with #[DomainIdentifier].',
            $stateClass,
        ));
    }
}
