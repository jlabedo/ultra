<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

trait ReducerTrait
{
    /** @var array<string, string>|null eventClass => method name (cached per class) */
    private static ?array $__reducerHandlers = null;

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
                $methodName = $handlers[$eventClass];
                $instance->$methodName($event);
            }
        }

        return $instance;
    }

    /**
     * @return array<string, string> eventClass => method name
     */
    private static function resolveHandlers(string $class): array
    {
        if (self::$__reducerHandlers !== null) {
            return self::$__reducerHandlers;
        }

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
            $handlers[$eventClass] = $method->getName();
        }

        self::$__reducerHandlers = $handlers;

        return $handlers;
    }
}
