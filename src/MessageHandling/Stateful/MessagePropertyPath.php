<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

final class MessagePropertyPath
{
    public static function exists(\ReflectionClass $message, string $path): bool
    {
        $segments = explode('.', $path);
        if (in_array('', $segments, true)) {
            return false;
        }

        $class = $message;
        foreach ($segments as $index => $segment) {
            if (!$class->hasProperty($segment)) {
                return false;
            }

            if ($index === array_key_last($segments)) {
                return true;
            }

            $type = $class->getProperty($segment)->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                return false;
            }
            $className = $type->getName();
            if (!class_exists($className)) {
                return false;
            }

            $class = new \ReflectionClass($className);
        }

        return true;
    }

    public static function value(object $message, string $path): mixed
    {
        $value = $message;
        foreach (explode('.', $path) as $segment) {
            if (!is_object($value)) {
                throw new \LogicException(sprintf(
                    'Message property path "%s" cannot be read because "%s" is %s.',
                    $path,
                    $segment,
                    get_debug_type($value),
                ));
            }

            // Closure::call binds $this to the current object and its property scope.
            // @mago-expect analysis:undefined-variable
            // @mago-expect analysis:mixed-property-access
            // @mago-expect analysis:string-member-selector
            $getter = fn(string $property): mixed => $this->{$property};
            $value = $getter->call($value, $segment);
        }

        return $value;
    }
}
