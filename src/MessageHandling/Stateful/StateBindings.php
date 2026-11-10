<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

use Ultra\MessageHandling\Attribute\HandlerMatchContext;
use Ultra\StatefulMessageHandling\Attribute\BindTo;

/**
 * How the state parameters of one handler get their ids, shared by the state-loading aspects.
 *
 * A state id comes from a message property path or from a message method whose parameters are other states of the
 * same handler, matched by name. Without #[BindTo], the conventional name targets a property or a public method.
 * Event-sourced tags also accept a property named exactly like the tag. Multiple candidates are ambiguous;
 * #[BindTo('path')] and #[BindTo(method: 'name')] choose explicitly. Multi-tag event-sourced
 * states resolve one id per tag, with tag-specific overrides. The union of all id method sources determines when
 * a state can load. Dependencies are resolved at compile time into loading rounds: round 0 holds states that depend on no other
 * state, round n the states whose sources are all loaded in earlier rounds.
 *
 * @phpstan-type IdBinding array{property: ?string, method: ?string, sources: list<string>}
 * @phpstan-type Binding array{
 *     index: int,
 *     parameterName: string,
 *     stateClass: class-string,
 *     allowsNull: bool,
 *     ids: array<array-key, IdBinding>,
 *     sources: list<string>
 * }
 * @phpstan-type Plan array{bindings: list<Binding>, rounds: list<list<int>>}
 */
final class StateBindings
{
    /**
     * Compile time: analyzes the state parameters of a handler. Throws when a binding cannot be resolved.
     *
     * @param callable(class-string): bool   $isState              whether a parameter class is a state of this flavor
     * @param callable(class-string): array<array-key, string> $conventionalProperties identity names to message property paths;
     *                                                                           stored states use the empty identity name
     * @param bool                           $nullableStates       whether missing states are passed as null; a state
     *                                                             depending on a nullable state is then null too, so
     *                                                             it must be nullable itself
     *
     * @return Plan|null null when the handler has no state parameter of this flavor
     */
    public static function analyze(
        HandlerMatchContext $ctx,
        callable $isState,
        callable $conventionalProperties,
        bool $nullableStates,
    ): ?array {
        $method = $ctx->method();
        $messageClass = $ctx->messageClass();
        $messageRef = new \ReflectionClass($messageClass);
        /** @var list<\ReflectionParameter> $parameters */
        $parameters = $ctx->isSelfHandler() ? $method->getParameters() : array_slice($method->getParameters(), 1);

        /** @var array<string, array{index: int, parameterName: string, stateClass: class-string, allowsNull: bool, bindTo: list<BindTo>}> $states */
        $states = [];
        foreach ($parameters as $index => $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $stateClass = $type->getName();
            if (!class_exists($stateClass) || !$isState($stateClass)) {
                continue;
            }

            $bindTo = [];
            foreach ($parameter->getAttributes(BindTo::class) as $attribute) {
                $bindTo[] = $attribute->newInstance();
            }
            $states[$parameter->getName()] = [
                'index' => $index,
                'parameterName' => $parameter->getName(),
                'stateClass' => $stateClass,
                'allowsNull' => $type->allowsNull(),
                'bindTo' => $bindTo,
            ];
        }

        if ([] === $states) {
            return null;
        }

        $bindings = [];
        foreach ($states as $state) {
            $binding = [
                'index' => $state['index'],
                'parameterName' => $state['parameterName'],
                'stateClass' => $state['stateClass'],
                'allowsNull' => $state['allowsNull'],
                'ids' => [],
                'sources' => [],
            ];
            $properties = $conventionalProperties($state['stateClass']);
            $overrides = [];
            foreach ($state['bindTo'] as $bindTo) {
                $tag = $bindTo->tag;
                if (null === $tag) {
                    if (1 !== count($properties)) {
                        throw new \LogicException(sprintf(
                            'Binding state parameter "%s" of %s::%s() requires an explicit tag in #[BindTo] for a multi-tag state.',
                            $state['parameterName'],
                            $method->getDeclaringClass()->getName(),
                            $method->getName(),
                        ));
                    }
                    $tag = array_key_first($properties);
                }
                if (!array_key_exists($tag, $properties)) {
                    throw new \LogicException(sprintf(
                        'Unknown tag "%s" in #[BindTo] for state parameter "%s" of %s::%s().',
                        $tag,
                        $state['parameterName'],
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                    ));
                }
                if (isset($overrides[$tag])) {
                    throw new \LogicException(sprintf(
                        'Duplicate #[BindTo] for tag "%s" of state parameter "%s" of %s::%s().',
                        $tag,
                        $state['parameterName'],
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                    ));
                }
                $overrides[$tag] = $bindTo;
            }

            foreach ($properties as $tag => $property) {
                $override = $overrides[$tag] ?? null;
                $id = ['property' => $override?->property, 'method' => $override?->method, 'sources' => []];
                if (null === $override) {
                    [$id['property'], $id['method']] = self::conventionalBinding(
                        $messageRef,
                        $method,
                        $state,
                        $property,
                        '' === $tag ? null : (string) $tag,
                    );
                }
                if (null !== $id['method']) {
                    $id['sources'] = self::methodSources(
                        $messageRef,
                        $method,
                        $state,
                        $states,
                        $nullableStates,
                        $id['method'],
                    );
                } elseif (null === $id['property'] || !MessagePropertyPath::exists($messageRef, $id['property'])) {
                    throw new \LogicException(sprintf(
                        'Message %s has no property path "%s" required for state parameter "%s" of %s::%s(). '
                        . 'Either add the property path, or use #[BindTo("property.path")] to bind to a different message property path.',
                        $messageClass,
                        $id['property'],
                        $state['parameterName'],
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                    ));
                }
                $binding['ids'][$tag] = $id;
                $binding['sources'] = array_values(array_unique([...$binding['sources'], ...$id['sources']]));
            }

            $bindings[] = $binding;
        }

        return [
            'bindings' => $bindings,
            'rounds' => self::rounds($bindings, $method),
        ];
    }

    /**
     * Runtime: resolves ids round by round and loads the states.
     *
     * `$loadRound` receives each state's identity map for one round, keyed by binding position in `$plan['bindings']`, and returns the
     * loaded states under the same keys. A state whose source state is null is not requested and is null itself.
     *
     * @param Plan                                               $plan
     * @param callable(array<int, array<array-key, mixed>>): array<int, ?object> $loadRound
     *
     * @return list<?object> states in binding order, ready for `$proceed(provided: ...)`
     */
    public static function load(array $plan, object $message, callable $loadRound): array
    {
        /** @var array<string, ?object> $loaded */
        $loaded = [];
        foreach ($plan['rounds'] as $round) {
            $ids = [];
            foreach ($round as $position) {
                $binding = $plan['bindings'][$position];
                if (self::hasMissingSource($binding, $loaded)) {
                    $loaded[$binding['parameterName']] = null;

                    continue;
                }

                $ids[$position] = array_map(static fn(array $id): mixed => self::id(
                    $message,
                    $id,
                    $loaded,
                ), $binding['ids']);
            }

            foreach ($loadRound($ids) as $position => $state) {
                $loaded[$plan['bindings'][$position]['parameterName']] = $state;
            }
        }

        return array_map(static fn(array $binding): ?object => $loaded[$binding['parameterName']], $plan['bindings']);
    }

    /**
     * @param IdBinding              $binding
     * @param array<string, ?object> $loaded
     */
    private static function id(object $message, array $binding, array $loaded): mixed
    {
        if (null !== $binding['property']) {
            return MessagePropertyPath::value($message, $binding['property']);
        }

        $arguments = [];
        foreach ($binding['sources'] as $source) {
            $arguments[$source] = $loaded[$source];
        }

        $method = $binding['method'];
        assert(null !== $method, 'A binding without a property must declare a method.');

        // @mago-expect analysis:string-member-selector
        return $message->{$method}(...$arguments);
    }

    /**
     * @param Binding                $binding
     * @param array<string, ?object> $loaded
     */
    private static function hasMissingSource(array $binding, array $loaded): bool
    {
        foreach ($binding['sources'] as $source) {
            if (null === $loaded[$source]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Without #[BindTo], choose exactly one conventional property or public instance method.
     * Event-sourced tags also accept their own name as a property. Stored states have no tag.
     * Reject multiple candidates so adding a property cannot silently change the loaded identity.
     *
     * @param array{index: int, parameterName: string, stateClass: class-string, allowsNull: bool, bindTo: list<BindTo>} $state
     *
     * @return array{?string, ?string} property path or method name
     */
    private static function conventionalBinding(
        \ReflectionClass $messageRef,
        \ReflectionMethod $handler,
        array $state,
        string $name,
        ?string $tag,
    ): array {
        $propertyNames = null === $tag ? [$name] : array_values(array_unique([$name, $tag]));
        /** @var list<array{?string, ?string}> $candidates */
        $candidates = [];
        $expected = [];
        foreach ($propertyNames as $property) {
            $expected[] = sprintf('property "%s"', $property);
            if ($messageRef->hasProperty($property)) {
                $candidates[] = [$property, null];
            }
        }
        $expected[] = sprintf('method "%s()"', $name);
        if (
            $messageRef->hasMethod($name)
            && $messageRef->getMethod($name)->isPublic()
            && !$messageRef->getMethod($name)->isStatic()
        ) {
            $candidates[] = [null, $name];
        }

        if (count($candidates) > 1) {
            $descriptions = array_map(static fn(array $candidate): string => null !== $candidate[0]
                ? sprintf('property "%s"', $candidate[0])
                : sprintf('method "%s()"', $candidate[1]), $candidates);
            throw new \LogicException(sprintf(
                'Message %s has ambiguous conventional bindings (%s) for state parameter "%s" of %s::%s(). '
                . 'Use #[BindTo] to choose a source explicitly.',
                $messageRef->getName(),
                implode(', ', $descriptions),
                $state['parameterName'],
                $handler->getDeclaringClass()->getName(),
                $handler->getName(),
            ));
        }

        if ([] === $candidates) {
            throw new \LogicException(sprintf(
                'Message %s has no conventional binding for state parameter "%s" of %s::%s(). '
                . 'Expected %s. Use #[BindTo] to choose a source explicitly.',
                $messageRef->getName(),
                $state['parameterName'],
                $handler->getDeclaringClass()->getName(),
                $handler->getName(),
                implode(', ', $expected),
            ));
        }

        return $candidates[0];
    }

    /**
     * @param array{index: int, parameterName: string, stateClass: class-string, allowsNull: bool, bindTo: list<BindTo>}                $state
     * @param array<string, array{index: int, parameterName: string, stateClass: class-string, allowsNull: bool, bindTo: list<BindTo>}> $states
     *
     * @return list<string>
     */
    private static function methodSources(
        \ReflectionClass $messageRef,
        \ReflectionMethod $handler,
        array $state,
        array $states,
        bool $nullableStates,
        string $methodName,
    ): array {
        $context = sprintf(
            'Method "%s" binding state parameter "%s" of %s::%s()',
            $methodName,
            $state['parameterName'],
            $handler->getDeclaringClass()->getName(),
            $handler->getName(),
        );

        if (!$messageRef->hasMethod($methodName)) {
            throw new \LogicException(sprintf(
                '%s: message %s has no method "%s".',
                $context,
                $messageRef->getName(),
                $methodName,
            ));
        }
        $method = $messageRef->getMethod($methodName);
        if (!$method->isPublic() || $method->isStatic()) {
            throw new \LogicException(sprintf(
                '%s: %s::%s() must be a public instance method.',
                $context,
                $messageRef->getName(),
                $methodName,
            ));
        }

        $sources = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $source = $states[$name] ?? null;
            if (null === $source) {
                throw new \LogicException(sprintf(
                    '%s: parameter "$%s" of %s::%s() does not match any state parameter of the handler by name.',
                    $context,
                    $name,
                    $messageRef->getName(),
                    $methodName,
                ));
            }

            $type = $parameter->getType();
            if (
                !$type instanceof \ReflectionNamedType
                || $type->getName() !== $source['stateClass']
                || $parameter->isVariadic()
            ) {
                throw new \LogicException(sprintf(
                    '%s: parameter "$%s" of %s::%s() must be typed %s to match the state parameter of the same name.',
                    $context,
                    $name,
                    $messageRef->getName(),
                    $methodName,
                    $source['stateClass'],
                ));
            }

            if ($nullableStates && $source['allowsNull'] && !$state['allowsNull']) {
                throw new \LogicException(sprintf(
                    '%s: state parameter "%s" depends on the nullable state parameter "%s", so it is null whenever "%s" is missing and must be nullable too.',
                    $context,
                    $state['parameterName'],
                    $name,
                    $name,
                ));
            }

            $sources[] = $name;
        }

        return $sources;
    }

    /**
     * Groups bindings into loading rounds (topological levels of the dependency graph).
     *
     * @param list<Binding> $bindings
     *
     * @return list<list<int>>
     */
    private static function rounds(array $bindings, \ReflectionMethod $handler): array
    {
        $positions = [];
        foreach ($bindings as $position => $binding) {
            $positions[$binding['parameterName']] = $position;
        }

        /** @var array<int, int> $levels */
        $levels = [];
        /** @var array<int, true> $visiting */
        $visiting = [];

        $level = /** @param list<string> $path */ static function (int $position, array $path) use (
            &$level,
            &$levels,
            &$visiting,
            $bindings,
            $positions,
            $handler,
        ): int {
            if (isset($levels[$position])) {
                return $levels[$position];
            }

            $name = $bindings[$position]['parameterName'];
            if (isset($visiting[$position])) {
                throw new \LogicException(sprintf(
                    'State parameters of %s::%s() have a cyclic method binding dependency: %s.',
                    $handler->getDeclaringClass()->getName(),
                    $handler->getName(),
                    implode(' -> ', [...array_slice($path, (int) array_search($name, $path, true)), $name]),
                ));
            }

            $visiting[$position] = true;
            $result = 0;
            foreach ($bindings[$position]['sources'] as $source) {
                $result = max($result, $level($positions[$source], [...$path, $name]) + 1);
            }
            unset($visiting[$position]);

            return $levels[$position] = $result;
        };

        $rounds = [];
        foreach (array_keys($bindings) as $position) {
            $rounds[$level($position, [])][] = $position;
        }
        ksort($rounds);

        return array_values($rounds);
    }
}
