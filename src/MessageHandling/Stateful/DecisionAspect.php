<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\Events\EventRegistryInterface;
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
        if (null === $eventSourced && null === $stored) {
            return null;
        }

        return MatchResult::apply(config: ['eventSourced' => $eventSourced, 'stored' => $stored], provides: [
            ...array_column($eventSourced['bindings'] ?? [], 'index'),
            ...array_column($stored['bindings'] ?? [], 'index'),
        ]);
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $states = [];
        $appendConditions = [];
        $stored = null;
        $eventSourced = null;
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
        $events = [];
        foreach (is_array($result->value) ? $result->value : [$result->value] as $effect) {
            if (!is_object($effect)) {
                continue;
            }

            $stored?->saveIfMapped($effect);
            $name = $this->eventRegistry?->getName($effect::class);
            if (null === $name) {
                continue;
            }

            $events[] = [
                'event' => $effect,
                'name' => $name,
                'context' => $this->contextPropagator->createChildContext($result->context),
            ];
        }

        if ([] === $events) {
            return $result;
        }

        $eventSourced?->append($events, $appendConditions);
        foreach ($events as $record) {
            $this->eventBus?->publish($record['event'], $record['context']);
        }

        return $result;
    }
}
