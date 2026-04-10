<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Aspect;

use Ultra\DecisionEngine\Attribute\BindTo;
use Ultra\DecisionEngine\Attribute\DecisionHandler;
use Ultra\DecisionEngine\Attribute\EventSourcedEntity;
use Ultra\DecisionEngine\Engine;
use Ultra\DecisionEngine\EventSourcedEntityLoader;
use Ultra\DecisionEngine\Exception\CommandHasNoCheckException;
use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\DomainIdentifier as EventStoreDomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;

final class DecisionEngineAspect
{
    private const CONTEXT_DECISION_MODE = 'decision_mode';
    private const MODE_CHECK = 'check';

    public function __construct(
        private readonly Engine $engine,
        private readonly EventStore $eventStore,
        private readonly EventRegistryInterface $eventClassRegistry,
        private readonly UltraNormalizer $normalizer,
        private readonly ?EventBusInterface $eventBus = null,
    ) {}

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();
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
                : $domainIdentifierName.'Id';
            if (! $commandRef->hasProperty($commandProperty)) {
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
        $loadResult = $this->eventStore->load(StreamQuery::anyOf(...array_column($preparedStates, 'query')));

        $states = [];
        $stateByName = [];

        foreach ($preparedStates as $preparedState) {
            $state = $preparedState['loader']->hydrate(
                $loadResult->events,
                new EventStoreDomainIdentifier(
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
        $emittedEvents = $proceedResult->value;
        if (! is_array($emittedEvents)) {
            return $proceedResult;
        }

        $this->eventStore->append($this->toEvents($emittedEvents), $loadResult->queryVersion);
        if (null !== $this->eventBus) {
            foreach ($emittedEvents as $emittedEvent) {
                $this->eventBus->publish($emittedEvent, $proceedResult->context);
            }
        }

        return $proceedResult;
    }

    /**
     * @param list<object> $events
     *
     * @return list<Event>
     */
    private function toEvents(array $events): array
    {
        return array_map(
            $this->toEvent(...),
            $events,
        );
    }

    private function toEvent(object $event): Event
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

        return new Event(
            eventType: $eventType,
            payload: $payload,
            domainIds: $domainIds,
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
            $loader = $this->engine->loader($stateParam['stateClass']);
            $identifier = new EventStoreDomainIdentifier(
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
