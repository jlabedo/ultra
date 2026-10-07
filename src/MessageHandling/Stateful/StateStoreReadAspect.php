<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StatefulMessageHandling\Attribute\StateReader;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StateStore\StateStore;

/** @phpstan-import-type Plan from StateBindings */
final readonly class StateStoreReadAspect
{
    public function __construct(
        private StateStore $stateStore,
    ) {}

    /**
     * @param Plan $config
     * @param callable(...): \Ultra\MessageHandling\ProceedResult $proceed
     */
    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        if ([] === $ctx->method()->getAttributes(StateReader::class)) {
            return null;
        }

        $plan = StateBindings::analyze(
            $ctx,
            self::isStateClass(...),
            self::getStateIdProperty(...),
            nullableStates: true,
        );

        return null === $plan ? null : MatchResult::apply($plan, array_column($plan['bindings'], 'index'));
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $states = StateBindings::load($config, $message, function (array $ids) use ($config): array {
            $states = [];
            foreach ($ids as $position => $id) {
                $binding = $config['bindings'][$position];
                if (!is_string($id) && !is_int($id) && !is_object($id)) {
                    throw new \LogicException(sprintf(
                        'Id of state parameter "%s" must be string, int, or object, got %s.',
                        $binding['parameterName'],
                        get_debug_type($id),
                    ));
                }

                $state = $this->stateStore->load($binding['stateClass'], $id);
                if (null === $state && true !== $binding['allowsNull']) {
                    throw new StateNotFoundException($binding['stateClass'], $id);
                }

                $states[$position] = $state;
            }

            return $states;
        });

        return $proceed(provided: $states);
    }

    private static function isStateClass(string $class): bool
    {
        return class_exists($class) && [] !== new \ReflectionClass($class)->getAttributes(State::class);
    }

    /**
     * @param class-string $stateClass
     */
    private static function getStateIdProperty(string $stateClass): string
    {
        foreach (new \ReflectionClass($stateClass)->getProperties() as $property) {
            if ([] !== $property->getAttributes(StateId::class)) {
                return $property->getName();
            }
        }

        throw new \LogicException(sprintf('State class %s has no property marked with #[StateId].', $stateClass));
    }
}
