<?php

declare(strict_types=1);

namespace Ultra\StatefulMessageHandling;

final class MessagePropertyPath
{
    public static function exists(\ReflectionClass $message, string $path): bool
    {
        $segments = explode('.', $path);
        if ([] === $segments || in_array('', $segments, true)) {
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
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
                return false;
            }

            $class = new \ReflectionClass($type->getName());
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

            $getter = function (string $property): mixed {
                return $this->{$property};
            };
            $value = $getter->call($value, $segment);
        }

        return $value;
    }
}
