<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;

/** @phpstan-import-type Plan from StateBindings */
final readonly class StoredDecisionStates
{
    public function __construct(
        private StateStore $stateStore,
        private StateMetadataRegistry $metadataRegistry,
    ) {}

    /** @return Plan|null */
    public static function bindings(HandlerMatchContext $ctx): ?array
    {
        return StateBindings::analyze(
            $ctx,
            self::isStateClass(...),
            self::getStateIdProperties(...),
            nullableStates: true,
        );
    }

    /**
     * @param Plan $config
     * @return list<?object>
     */
    public function load(array $config, object $message): array
    {
        return StateBindings::load($config, $message, function (array $ids) use ($config): array {
            $states = [];
            foreach ($ids as $position => $values) {
                $id = $values[''];
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
    }

    public function saveIfMapped(object $effect): void
    {
        if ($this->metadataRegistry->has($effect::class)) {
            $this->stateStore->save($effect);
        }
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
     * @return array{'': string}
     */
    private static function getStateIdProperties(string $stateClass): array
    {
        $reflection = new \ReflectionClass($stateClass);
        foreach ($reflection->getProperties() as $property) {
            if ([] !== $property->getAttributes(StateId::class)) {
                return ['' => $property->getName()];
            }
        }

        throw new \LogicException(sprintf('State class %s has no property marked with #[StateId].', $stateClass));
    }
}
