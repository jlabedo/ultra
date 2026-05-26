<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StatefulMessageHandling\Exception\CommandHasNoCheckException;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\EventSourcedEntityLoader;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\ProceedResult;

final class EventSourcedDecisionAspect
{
    private const CONTEXT_DECISION_MODE = 'decision_mode';
    private const MODE_CHECK = 'check';

    public function __construct(
        private readonly EventSourcedStateStore $stateStore,
        private readonly EventStore $eventStore,
        private readonly EventRegistryInterface $eventClassRegistry,
        private readonly UltraNormalizer $normalizer,
        private readonly MessageContextPropagatorInterface $messageContextPropagator,
        private readonly ?EventBusInterface $eventBus = null,
    ) {}

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();
        if ([] === $method->getAttributes(DecisionHandler::class)) {
            return null;
        }

        $commandRef = $method->getDeclaringClass();
        $commandClass = $commandRef->getName();

        $stateParams = [];
        $provides = [];

        foreach ($method->getParameters() as $index => $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeClass = $type->getName();
            if (!EventSourcedDecisionAspect::isStateClass($typeClass)) {
                continue;
            }

            $domainIdentifierName = EventSourcedDecisionAspect::getDomainIdentifierName($typeClass);
            $bindTo = null;
            foreach ($parameter->getAttributes(BindTo::class) as $attr) {
                $bindTo = $attr->newInstance();

                break;
            }
            $commandProperty = null !== $bindTo
                ? $bindTo->property
                : $domainIdentifierName.'Id';
            if (!$commandRef->hasProperty($commandProperty)) {
                throw new \LogicException(sprintf(
                    'Command %s has no property "%s" required for decision handler parameter "%s" (domain identifier "%s"). '
                        .'Either add the property or use #[BindTo("propertyName")] to bind to a different command property.',
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

        if ([] === $provides) {
            return null;
        }

        return MatchResult::apply(
            config: [
                'stateParams' => $stateParams,
                'checkParamNames' => $checkParamNames,
            ],
            provides: $provides,
        );
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $stateParams = $config['stateParams'] ?? [];
        $preparedStates = $this->prepareStates($message, $stateParams);
        $query = StreamQuery::anyOf(...array_column($preparedStates, 'query'));
        $loadResult = $this->eventStore->load($query);

        $states = [];
        $stateByName = [];

        foreach ($preparedStates as $preparedState) {
            $state = $preparedState['loader']->hydrate(
                $loadResult->events,
                new DomainIdentifier(
                    $preparedState['domainIdentifierName'],
                    $preparedState['streamValue'],
                ),
            );

            $states[] = $state;
            $stateByName[$preparedState['parameterName']] = $state;
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

            return new ProceedResult(
                is_array($violations) ? $violations : [],
                $context,
            );
        }

        $proceedResult = $proceed(provided: $states);
        $emittedEvents = $this->eventEffectsFrom($proceedResult->value);
        if ([] === $emittedEvents) {
            return $proceedResult;
        }

        $eventRecords = $this->prepareEventRecords($emittedEvents, $proceedResult->context);

        $this->eventStore->append(array_column($eventRecords, 'storedEvent'), $loadResult->appendCondition());
        if (null !== $this->eventBus) {
            foreach ($eventRecords as $eventRecord) {
                $this->eventBus->publish($eventRecord['event'], $eventRecord['context']);
            }
        }

        return $proceedResult;
    }

    /**
     * @return list<object>
     */
    private function eventEffectsFrom(mixed $value): array
    {
        $effects = [];
        foreach ($this->objectsFrom($value) as $object) {
            if (null !== $this->eventClassRegistry->getName($object::class)) {
                $effects[] = $object;
            }
        }

        return $effects;
    }

    /**
     * @return list<object>
     */
    private function objectsFrom(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (is_object($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $objects = [];
        foreach ($value as $item) {
            if (is_object($item)) {
                $objects[] = $item;
            }
        }

        return $objects;
    }

    /**
     * @param list<object> $events
     *
     * @return list<array{event: object, context: MessageContext, storedEvent: Event}>
     */
    private function prepareEventRecords(array $events, MessageContext $parentContext): array
    {
        $records = [];
        foreach ($events as $event) {
            $eventContext = $this->messageContextPropagator->createChildContext($parentContext);
            $records[] = [
                'event' => $event,
                'context' => $eventContext,
                'storedEvent' => $this->toEvent($event, $eventContext->propagated()->all()),
            ];
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function toEvent(object $event, array $metadata = []): Event
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
        if (!is_array($payload)) {
            throw new \LogicException(sprintf('Event %s normalized payload must be an array, got %s.', $event::class, get_debug_type($payload)));
        }

        return new Event(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
            metadata: $metadata,
        );
    }

    /**
     * @param list<array<string, mixed>> $stateParams
     *
     * @return list<array{parameterName: string, domainIdentifierName: string, streamValue: string, loader: EventSourcedEntityLoader, query: StreamQuery}>
     */
    private function prepareStates(object $command, array $stateParams): array
    {
        $preparedStates = [];
        foreach ($stateParams as $stateParam) {
            $streamValue = $this->getCommandPropertyValue($command, $stateParam['commandProperty']);
            $loader = $this->stateStore->loader($stateParam['stateClass']);
            $identifier = new DomainIdentifier(
                $stateParam['domainIdentifierName'],
                $streamValue,
            );

            $preparedStates[] = [
                'parameterName' => $stateParam['parameterName'],
                'domainIdentifierName' => $stateParam['domainIdentifierName'],
                'streamValue' => $streamValue,
                'loader' => $loader,
                'query' => $loader->query($identifier),
            ];
        }

        return $preparedStates;
    }

    private function getCommandPropertyValue(object $command, string $property): string
    {
        $getter = function (string $prop): mixed {
            return $this->{$prop};
        };
        $value = $getter->call($command, $property);
        if (!is_string($value)) {
            throw new \LogicException(sprintf('Command property "%s" must be string, got %s.', $property, get_debug_type($value)));
        }

        return $value;
    }

    private static function isStateClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return [] !== new \ReflectionClass($class)->getAttributes(EventSourcedEntity::class);
    }

    private static function getDomainIdentifierName(string $stateClass): string
    {
        $ref = new \ReflectionClass($stateClass);
        $attributes = $ref->getAttributes(EventSourcedEntity::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf(
                'State class %s is not marked with #[EventSourcedEntity].',
                $stateClass,
            ));
        }

        /** @var EventSourcedEntity $entity */
        $entity = $attributes[0]->newInstance();
        if (1 !== count($entity->tags)) {
            throw new \LogicException(sprintf(
                'Decision handler state class %s must declare exactly one event-sourced tag.',
                $stateClass,
            ));
        }

        return $entity->tags[0];
    }
}
