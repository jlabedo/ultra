<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Ultra\Schema\SchemaDefinition;

/** @internal */
final class SchemaDefinitionFile
{
    public static function load(string $path): SchemaDefinition
    {
        $resolved = realpath($path);
        if (false === $resolved || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException(sprintf('Cannot read schema definition "%s".', $path));
        }

        $definition = require $resolved;
        if (!$definition instanceof SchemaDefinition) {
            throw new \UnexpectedValueException(sprintf(
                'Schema definition "%s" must return a SchemaDefinition.',
                $path,
            ));
        }

        return $definition;
    }
}
