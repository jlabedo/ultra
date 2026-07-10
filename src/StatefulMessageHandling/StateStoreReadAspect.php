<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\MessageHandling\Attribute\Around;
use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\MessageHandling\Attribute\MatchResult;
use Ultra\MessageHandling\MessageContext;
use Ultra\MessageHandling\ProceedResult;
use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\StateNotFoundException;
use Ultra\StateStore\StateStore;
use Ultra\StatefulMessageHandling\Attribute\BindTo;
use Ultra\StatefulMessageHandling\Attribute\StateReader;

final readonly class StateStoreReadAspect
{
    public function __construct(
        private StateStore $stateStore,
    ) {}

    #[Around(static function (HandlerMatchContext $ctx): ?MatchResult {
        $method = $ctx->method();
        if ([] === $method->getAttributes(StateReader::class)) {
            return null;
        }

        $messageRef = $method->getDeclaringClass();
        $messageClass = $messageRef->getName();
        $stateParams = [];
        $provides = [];

        foreach ($method->getParameters() as $index => $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $stateClass = $type->getName();
            if (!self::isStateClass($stateClass)) {
                continue;
            }

            $bindTo = $parameter->getAttributes(BindTo::class)[0] ?? null;
            $commandProperty = null === $bindTo
                ? self::getStateIdProperty($stateClass)
                : $bindTo->newInstance()->property;

            if (!MessagePropertyPath::exists($messageRef, $commandProperty)) {
                throw new \LogicException(sprintf(
                    'Message %s has no property path "%s" required for state parameter "%s". '
                        .'Either add the property path or use #[BindTo("property.path")] to bind to a different message property path.',
                    $messageClass,
                    $commandProperty,
                    $parameter->getName(),
                ));
            }

            $stateParams[] = [
                'stateClass' => $stateClass,
                'commandProperty' => $commandProperty,
                'allowsMissing' => $type->allowsNull(),
            ];
            $provides[] = $index;
        }

        return [] === $provides
            ? null
            : MatchResult::apply(['stateParams' => $stateParams], $provides);
    })]
    public function around(callable $proceed, object $message, MessageContext $context, array $config): ProceedResult
    {
        $states = [];

        foreach ($config['stateParams'] ?? [] as $stateParam) {
            $id = $this->messagePropertyValue($message, $stateParam['commandProperty']);
            $state = $this->stateStore->load($stateParam['stateClass'], $id);

            if (null === $state && true !== $stateParam['allowsMissing']) {
                throw new StateNotFoundException($stateParam['stateClass'], $id);
            }

            $states[] = $state;
        }

        return $proceed(provided: $states);
    }

    private function messagePropertyValue(object $message, string $property): int|string|object
    {
        $value = MessagePropertyPath::value($message, $property);
        if (!is_string($value) && !is_int($value) && !is_object($value)) {
            throw new \LogicException(sprintf('Message property path "%s" must be string, int, or object, got %s.', $property, get_debug_type($value)));
        }

        return $value;
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
        foreach ((new \ReflectionClass($stateClass))->getProperties() as $property) {
            if ([] !== $property->getAttributes(StateId::class)) {
                return $property->getName();
            }
        }

        throw new \LogicException(sprintf('State class %s has no property marked with #[StateId].', $stateClass));
    }
}
