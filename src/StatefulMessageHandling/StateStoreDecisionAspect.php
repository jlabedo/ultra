<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\DecisionHandler;
use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StateStore\StateMetadataRegistry;
use Ultra\StateStore\StateStore;

final readonly class StateStoreDecisionAspect
{
    public function __construct(
        private StateStore $stateStore,
        private StateMetadataRegistry $metadataRegistry,
    ) {}

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();
        if ([] === $method->getAttributes(DecisionHandler::class)) {
            return null;
        }

        $commandClass = $ctx->messageClass();
        $commandRef = new \ReflectionClass($commandClass);

        $stateParams = [];
        $provides = [];

        $parameters = $ctx->isSelfHandler()
            ? $method->getParameters()
            : array_slice($method->getParameters(), 1);

        foreach ($parameters as $index => $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $stateClass = $type->getName();
            if (!StateStoreDecisionAspect::isStateClass($stateClass)) {
                continue;
            }

            $bindTo = null;
            foreach ($parameter->getAttributes(BindTo::class) as $attribute) {
                $bindTo = $attribute->newInstance();

                break;
            }

            $commandProperty = $bindTo?->property ?? StateStoreDecisionAspect::getStateIdProperty($stateClass);
            if (!MessagePropertyPath::exists($commandRef, $commandProperty)) {
                throw new \LogicException(sprintf(
                    'Command %s has no property path "%s" required for decision handler parameter "%s". '
                        .'Either add the property path or use #[BindTo("property.path")] to bind to a different command property path.',
                    $commandClass,
                    $commandProperty,
                    $parameter->getName(),
                ));
            }

            $stateParams[] = [
                'parameterName' => $parameter->getName(),
                'stateClass' => $stateClass,
                'commandProperty' => $commandProperty,
                'allowsMissing' => $type->allowsNull(),
            ];
            $provides[] = $index;
        }

        if ([] === $provides) {
            return null;
        }

        return MatchResult::apply(
            config: [
                'stateParams' => $stateParams,
            ],
            provides: $provides,
        );
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $states = [];

        foreach ($config['stateParams'] ?? [] as $stateParam) {
            $id = $this->getCommandPropertyValue($message, $stateParam['commandProperty']);
            $stateClass = $stateParam['stateClass'];

            $state = $this->stateStore->load($stateClass, $id);
            if (null === $state) {
                if (true !== $stateParam['allowsMissing']) {
                    throw new StateNotFoundException($stateClass, $id);
                }

                $this->stateStore->expectMissing($stateClass, $id);
            } else {
                $this->stateStore->expect($state);
            }

            $states[] = $state;
        }

        if (DecisionMode::isCheck($context)) {
            return DecisionMode::check(static fn (): ProceedResult => $proceed(provided: $states), $context);
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
            if ($this->metadataRegistry->has($object::class)) {
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

    private function getCommandPropertyValue(object $command, string $property): int|string|object
    {
        $value = MessagePropertyPath::value($command, $property);
        if (!is_string($value) && !is_int($value) && !is_object($value)) {
            throw new \LogicException(sprintf('Command property path "%s" must be string, int, or object, got %s.', $property, get_debug_type($value)));
        }

        return $value;
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

        throw new \LogicException(sprintf(
            'State class %s has no property marked with #[StateId].',
            $stateClass,
        ));
    }
}
