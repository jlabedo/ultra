<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration;

final class PhpCode
{
    public const INDENT = '    ';

    public static function classLiteral(string $className): string
    {
        return '\\'.ltrim($className, '\\').'::class';
    }

    public static function fqcn(string $className): string
    {
        return '\\'.ltrim($className, '\\');
    }

    public static function indent(string $code, int $levels = 1): string
    {
        $prefix = str_repeat(self::INDENT, $levels);

        return implode("\n", array_map(
            static fn (string $line): string => '' === $line ? '' : $prefix.$line,
            explode("\n", $code),
        ));
    }

    /**
     * @param list<string> $values
     */
    public static function exportStringList(array $values): string
    {
        return '['.implode(', ', array_map(self::exportString(...), $values)).']';
    }

    public static function exportString(string $value): string
    {
        return var_export($value, true);
    }

    public static function exportValue(mixed $value): string
    {
        return var_export($value, true);
    }

    public static function identifier(string $value, string $fallback = 'value'): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_]+/', '_', $value) ?? $value;
        $normalized = trim($normalized, '_');

        if ('' === $normalized) {
            $normalized = $fallback;
        }

        if (preg_match('/^[0-9]/', $normalized)) {
            $normalized = $fallback.'_'.$normalized;
        }

        return $normalized;
    }
}
