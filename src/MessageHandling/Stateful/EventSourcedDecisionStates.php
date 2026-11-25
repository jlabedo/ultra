<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\Events\EventRegistryInterface;
use Ultra\EventSourcedState\Attribute\EventSourcedEntity;
use Ultra\EventSourcedState\EventSourcedStateStore;
use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;

/** @phpstan-import-type Plan from StateBindings */
final readonly class EventSourcedDecisionStates
{
    public function __construct(
        private EventSourcedStateStore $stateStore,
        private EventStore $eventStore,
        private EventRegistryInterface $eventClassRegistry,
        private UltraNormalizer $normalizer,
    ) {}

    /** @return Plan|null */
    public static function bindings(HandlerMatchContext $ctx): ?array
    {
        return StateBindings::analyze(
            $ctx,
            self::isStateClass(...),
            self::getTagProperties(...),
            nullableStates: false,
        );
    }

    /**
     * @param Plan $config
     * @return array{list<?object>, list<AppendCondition>}
     */
    public function load(array $config, object $message): array
    {
        // One load per round; every round's append condition guards the final append, so a concurrent change to a
        // source state (whose content decided a dependent id) makes the append fail.
        $appendConditions = [];
        $states = StateBindings::load($config, $message, function (array $ids) use (
            $config,
            &$appendConditions,
        ): array {
            /** @var array<int, array{\Ultra\EventSourcedState\EventSourcedEntityLoader<object>, list<DomainIdentifier>, StreamQuery}> $prepared */
            $prepared = [];
            foreach ($ids as $position => $tagValues) {
                $binding = $config['bindings'][$position];
                $identifiers = [];
                foreach ($tagValues as $tag => $id) {
                    if (!is_string($id) && !$id instanceof \Stringable) {
                        throw new \LogicException(sprintf(
                            'Id of state parameter "%s" for tag "%s" must be string or Stringable, got %s.',
                            $binding['parameterName'],
                            $tag,
                            get_debug_type($id),
                        ));
                    }
                    $identifiers[] = new DomainIdentifier((string) $tag, $id);
                }
                $loader = $this->stateStore->loader($binding['stateClass']);
                $prepared[$position] = [$loader, $identifiers, $loader->query(...$identifiers)];
            }

            $loadResult = $this->eventStore->load(StreamQuery::anyOf(...array_map(
                static fn(array $state): StreamQuery => $state[2],
                $prepared,
            )));
            $appendConditions[] = $loadResult->appendCondition();

            return array_map(static fn(array $state): object => $state[0]->hydrate(
                $loadResult->events,
                ...$state[1],
            ), $prepared);
        });

        return [$states, $appendConditions];
    }

    /**
     * @param list<array{event: object, name: string, context: \Ultra\MessageHandling\MessageContext}> $events
     * @param list<AppendCondition> $appendConditions
     */
    public function append(array $events, array $appendConditions): void
    {
        $stored = [];
        foreach ($events as $record) {
            $stored[] = $this->toEvent($record['event'], $record['name'], $record['context']->propagated()->all());
        }

        $this->eventStore->append($stored, ...$appendConditions);
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function toEvent(object $event, string $eventType, array $metadata): Event
    {
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

    /**
     * @param class-string $stateClass
     * @return array<array-key, string>
     */
    private static function getTagProperties(string $stateClass): array
    {
        $ref = new \ReflectionClass($stateClass);
        $attributes = $ref->getAttributes(EventSourcedEntity::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf('State class %s is not marked with #[EventSourcedEntity].', $stateClass));
        }

        $entity = $attributes[0]->newInstance();
        if ([] === $entity->tags) {
            throw new \LogicException(sprintf(
                'Decision handler state class %s must declare at least one event-sourced tag.',
                $stateClass,
            ));
        }
        $properties = [];
        foreach ($entity->tags as $tag) {
            $properties[$tag] = $tag . 'Id';
        }

        return $properties;
    }
}
