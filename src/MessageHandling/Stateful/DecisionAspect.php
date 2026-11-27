<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\Events\EventRegistryInterface;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\Mapper\UltraNormalizer;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\MessageContextPropagatorInterface;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;

/** @phpstan-import-type Plan from StateBindings */
final readonly class DecisionAspect
{
    public function __construct(
        private ?StoredDecisionStates $storedStates,
        private ?EventSourcedDecisionStates $eventSourcedStates,
        private ?EventRegistryInterface $eventRegistry,
        private MessageContextPropagatorInterface $contextPropagator,
        private ?EventBusInterface $eventBus,
        private ?EventStore $eventStore,
        private ?UltraNormalizer $normalizer,
    ) {}

    /**
     * @param array{eventSourced: ?Plan, stored: ?Plan} $config
     * @param callable(...): ProceedResult $proceed
     */
    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        if ([] === $ctx->method()->getAttributes(DecisionHandler::class)) {
            return null;
        }

        $eventSourced = EventSourcedDecisionStates::bindings($ctx);
        $stored = StoredDecisionStates::bindings($ctx);

        return MatchResult::apply(config: ['eventSourced' => $eventSourced, 'stored' => $stored], provides: [
            ...array_column($eventSourced['bindings'] ?? [], 'index'),
            ...array_column($stored['bindings'] ?? [], 'index'),
        ]);
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $states = [];
        $appendConditions = [];
        if (null !== $config['eventSourced']) {
            $eventSourced = $this->eventSourcedStates ?? throw new \LogicException(
                'Event-sourced decisions require an event store.',
            );
            [$states, $appendConditions] = $eventSourced->load($config['eventSourced'], $message);
        }
        if (null !== $config['stored']) {
            $stored = $this->storedStates ?? throw new \LogicException('Stored-state decisions require a state store.');
            $states = [...$states, ...$stored->load($config['stored'], $message)];
        }

        if (DecisionMode::isCheck($context)) {
            return DecisionMode::check(static fn(): ProceedResult => $proceed(provided: $states), $context);
        }

        $result = $proceed(provided: $states);
        $returnedObjects = [];
        $storedEvents = [];
        $publications = [];
        $registry = $this->eventRegistry;
        foreach (is_array($result->value) ? $result->value : [$result->value] as $effect) {
            if (!is_object($effect)) {
                continue;
            }

            $returnedObjects[] = $effect;
            if (null === $registry || null === ($name = $registry->getName($effect::class))) {
                continue;
            }

            $context = $this->contextPropagator->createChildContext($result->context);
            $domainIds = $registry->getDomainIdentifiers($effect);
            if ([] !== $domainIds) {
                $storedEvents[] = $this->toStoredEvent($effect, $name, $domainIds, $context);
            }
            $publications[] = [$effect, $context];
        }

        if ([] !== $storedEvents && null === $this->eventStore) {
            throw new \LogicException('Persisting returned events requires an event store.');
        }
        if ([] !== $publications && null === $this->eventBus) {
            throw new \LogicException('Publishing returned events requires an event bus.');
        }

        foreach ($returnedObjects as $effect) {
            $this->storedStates?->saveIfMapped($effect);
        }
        if ([] !== $storedEvents) {
            $this->eventStore?->append($storedEvents, ...$appendConditions);
        }
        foreach ($publications as [$event, $context]) {
            $this->eventBus?->publish($event, $context);
        }

        return $result;
    }

    /** @param non-empty-array<string, string> $domainIds */
    private function toStoredEvent(object $event, string $name, array $domainIds, MessageContext $context): Event
    {
        $normalizer = $this->normalizer ?? throw new \LogicException(
            'Persisting returned events requires a normalizer.',
        );
        $payload = $normalizer->toScalar($event);
        if (!is_array($payload)) {
            throw new \LogicException(sprintf(
                'Event %s normalized payload must be an array, got %s.',
                $event::class,
                get_debug_type($payload),
            ));
        }

        return new Event(
            eventType: $name,
            payload: $payload,
            domainIds: $domainIds,
            metadata: $context->propagated()->all(),
        );
    }
}
