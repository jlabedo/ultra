<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

trait ReducerTrait
{
    /**
     * Create a new instance and apply events in order. Events with no matching handler are skipped.
     *
     * @param list<object> $events
     */
    public static function fromEvents(array $events): static
    {
        $instance = new static();

        $handlers = self::resolveHandlers(static::class);

        foreach ($events as $event) {
            $eventClass = $event::class;
            if (isset($handlers[$eventClass])) {
                $handlers[$eventClass]($instance, $event);
            }
        }

        return $instance;
    }

    /**
     * Return the list of event class names that this state handles.
     *
     * @return list<string>
     */
    public static function handledEventTypes(): array
    {
        $handlers = self::resolveHandlers(static::class);

        return array_keys($handlers);
    }

    /**
     * @return array<string, callable(static, object): void>
     */
    private static function resolveHandlers(string $class): array
    {
        $handlers = [];
        $ref = new \ReflectionClass($class);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PRIVATE) as $method) {
            $params = $method->getParameters();
            if (count($params) !== 1) {
                continue;
            }
            $type = $params[0]->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $eventClass = $type->getName();
            $handlers[$eventClass] = static function (self $instance, object $event) use ($method): void {
                $method->setAccessible(true);
                $method->invoke($instance, $event);
            };
        }

        return $handlers;
    }
}
