<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\Events\EventRegistryInterface;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
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
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

/**
 * @phpstan-type EventBinding array{
 *     index: int,
 *     parameterName: string,
 *     stateClass: class-string,
 *     allowsNull: bool,
 *     property: ?string,
 *     method: ?string,
 *     sources: list<string>,
 *     domainIdentifierName: string
 * }
 * @phpstan-type EventPlan array{bindings: list<EventBinding>, rounds: list<list<int>>}
 */
final class EventSourcedDecisionAspect
{
    public function __construct(
        private readonly EventSourcedStateStore $stateStore,
        private readonly EventStore $eventStore,
        private readonly EventRegistryInterface $eventClassRegistry,
        private readonly UltraNormalizer $normalizer,
        private readonly MessageContextPropagatorInterface $messageContextPropagator,
        private readonly ?EventBusInterface $eventBus = null,
    ) {}

    /**
     * @param EventPlan $config
     * @param callable(...): \Ultra\MessageHandling\ProceedResult $proceed
     */
    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        if ([] === $ctx->method()->getAttributes(DecisionHandler::class)) {
            return null;
        }

        $plan = StateBindings::analyze(
            $ctx,
            EventSourcedDecisionAspect::isStateClass(...),
            /** @param class-string $stateClass */ static fn(string $stateClass): string => (
                EventSourcedDecisionAspect::getDomainIdentifierName($stateClass) . 'Id'
            ),
            nullableStates: false,
        );
        if (null === $plan) {
            return null;
        }

        foreach ($plan['bindings'] as $position => $binding) {
            $plan['bindings'][$position]['domainIdentifierName'] = EventSourcedDecisionAspect::getDomainIdentifierName(
                $binding['stateClass'],
            );
        }

        return MatchResult::apply(config: $plan, provides: array_column($plan['bindings'], 'index'));
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        // One load per round; every round's append condition guards the final append, so a concurrent change to a
        // source state (whose content decided a dependent id) makes the append fail.
        $appendConditions = [];
        $states = StateBindings::load($config, $message, function (array $ids) use (
            $config,
            &$appendConditions,
        ): array {
            /** @var array<int, array{\Ultra\EventSourcedState\EventSourcedEntityLoader<object>, DomainIdentifier, StreamQuery}> $prepared */
            $prepared = [];
            foreach ($ids as $position => $id) {
                $binding = $config['bindings'][$position];
                if (!is_string($id)) {
                    throw new \LogicException(sprintf(
                        'Id of state parameter "%s" must be string, got %s.',
                        $binding['parameterName'],
                        get_debug_type($id),
                    ));
                }
                $identifier = new DomainIdentifier($binding['domainIdentifierName'], $id);
                $loader = $this->stateStore->loader($binding['stateClass']);
                $prepared[$position] = [$loader, $identifier, $loader->query($identifier)];
            }

            $loadResult = $this->eventStore->load(StreamQuery::anyOf(...array_map(
                static fn(array $state): StreamQuery => $state[2],
                $prepared,
            )));
            $appendConditions[] = $loadResult->appendCondition();

            return array_map(static fn(array $state): object => $state[0]->hydrate(
                $loadResult->events,
                $state[1],
            ), $prepared);
        });

        if (DecisionMode::isCheck($context)) {
            return DecisionMode::check(static fn(): ProceedResult => $proceed(provided: $states), $context);
        }

        $proceedResult = $proceed(provided: $states);
        $emittedEvents = $this->eventEffectsFrom($proceedResult->value);
        if ([] === $emittedEvents) {
            return $proceedResult;
        }

        $eventRecords = $this->prepareEventRecords($emittedEvents, $proceedResult->context);

        $this->eventStore->append(array_column($eventRecords, 'storedEvent'), ...$appendConditions);
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
            if (null === $this->eventClassRegistry->getName($object::class)) {
                continue;
            }

            $effects[] = $object;
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
            if (!is_object($item)) {
                continue;
            }

            $objects[] = $item;
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
     * @param array<array-key, mixed> $metadata
     */
    private function toEvent(object $event, array $metadata = []): Event
    {
        $eventType = $this->eventClassRegistry->getName($event::class);
        if (null === $eventType) {
            throw new \LogicException(sprintf('Event class %s has no #[Event] attribute.', $event::class));
        }

        $domainIds = $this->eventClassRegistry->getDomainIdentifiers($event);
        if ([] === $domainIds) {
            throw new \LogicException(sprintf(
                'Event class %s has no properties with #[DomainIdentifier].',
                $event::class,
            ));
        }

        $payload = $this->normalizer->toScalar($event);
        if (!is_array($payload)) {
            throw new \LogicException(sprintf(
                'Event %s normalized payload must be an array, got %s.',
                $event::class,
                get_debug_type($payload),
            ));
        }

        return new Event(eventType: $eventType, payload: $payload, domainIds: $domainIds, metadata: $metadata);
    }

    private static function isStateClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return [] !== new \ReflectionClass($class)->getAttributes(EventSourcedEntity::class);
    }

    /** @param class-string $stateClass */
    private static function getDomainIdentifierName(string $stateClass): string
    {
        $ref = new \ReflectionClass($stateClass);
        $attributes = $ref->getAttributes(EventSourcedEntity::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf('State class %s is not marked with #[EventSourcedEntity].', $stateClass));
        }

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
