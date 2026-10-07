<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;

/** @phpstan-import-type Plan from StateBindings */
final readonly class StateStoreDecisionAspect
{
    public function __construct(
        private StateStore $stateStore,
        private StateMetadataRegistry $metadataRegistry,
    ) {}

    /**
     * @param Plan $config
     * @param callable(...): \Ultra\MessageHandling\ProceedResult $proceed
     */
    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        if ([] === $ctx->method()->getAttributes(DecisionHandler::class)) {
            return null;
        }

        $plan = StateBindings::analyze(
            $ctx,
            StateStoreDecisionAspect::isStateClass(...),
            StateStoreDecisionAspect::getStateIdProperty(...),
            nullableStates: true,
        );

        return null === $plan
            ? null
            : MatchResult::apply(config: $plan, provides: array_column($plan['bindings'], 'index'));
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $states = StateBindings::load($config, $message, function (array $ids) use ($config): array {
            $states = [];
            foreach ($ids as $position => $id) {
                $binding = $config['bindings'][$position];
                $stateClass = $binding['stateClass'];
                $id = self::stateId($binding['parameterName'], $id);

                $state = $this->stateStore->load($stateClass, $id);
                if (null === $state) {
                    if (true !== $binding['allowsNull']) {
                        throw new StateNotFoundException($stateClass, $id);
                    }

                    $this->stateStore->expectMissing($stateClass, $id);
                } else {
                    $this->stateStore->expect($state);
                }

                $states[$position] = $state;
            }

            return $states;
        });

        if (DecisionMode::isCheck($context)) {
            return DecisionMode::check(static fn(): ProceedResult => $proceed(provided: $states), $context);
        }

        $proceedResult = $proceed(provided: $states);

        foreach ($this->stateEffectsFrom($proceedResult->value) as $state) {
            $this->stateStore->save($state);
        }

        return $proceedResult;
    }

    /**
     * @return list<object>
     */
    private function stateEffectsFrom(mixed $value): array
    {
        $effects = [];
        foreach ($this->objectsFrom($value) as $object) {
            if (!$this->metadataRegistry->has($object::class)) {
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

    private static function stateId(string $parameterName, mixed $id): int|string|object
    {
        if (!is_string($id) && !is_int($id) && !is_object($id)) {
            throw new \LogicException(sprintf(
                'Id of state parameter "%s" must be string, int, or object, got %s.',
                $parameterName,
                get_debug_type($id),
            ));
        }

        return $id;
    }

    private static function isStateClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return [] !== new \ReflectionClass($class)->getAttributes(State::class);
    }

    /**
     * @param class-string $stateClass
     */
    private static function getStateIdProperty(string $stateClass): string
    {
        $reflection = new \ReflectionClass($stateClass);
        foreach ($reflection->getProperties() as $property) {
            if ([] !== $property->getAttributes(StateId::class)) {
                return $property->getName();
            }
        }

        throw new \LogicException(sprintf('State class %s has no property marked with #[StateId].', $stateClass));
    }
}
