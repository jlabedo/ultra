<?php

declare(strict_types=1);

namespace Ultra\CodeGeneration;

final readonly class PhpCode
{
    public const INDENT = '    ';

    public function __construct(
        private string $code,
    ) {}

    public static function from(string|self $code): self
    {
        return $code instanceof self ? $code : new self($code);
    }

    public static function empty(): self
    {
        return new self('');
    }

    public static function classLiteral(string $className): string
    {
        return '\\'.ltrim($className, '\\').'::class';
    }

    public static function fqcn(string $className): string
    {
        return '\\'.ltrim($className, '\\');
    }

    public function indent(int $levels = 1): self
    {
        if ($levels === 0) {
            return $this;
        }
        $prefix = str_repeat(self::INDENT, $levels);

        return new self(implode("\n", array_map(
            static fn (string $line): string => '' === $line ? '' : $prefix.$line,
            explode("\n", $this->code),
        )));
    }

    public static function indentLevel(int $level): string
    {
        return str_repeat(self::INDENT, $level);
    }

    public function indentContinuationLines(int $levels): self
    {
        $lines = explode("\n", $this->code);
        if (1 === count($lines)) {
            return $this;
        }

        $prefix = self::indentLevel($levels);

        return new self(implode("\n", [
            $lines[0],
            ...array_map(
                static fn (string $line): string => '' === $line ? '' : $prefix.$line,
                array_slice($lines, 1),
            ),
        ]));
    }

    public function add(string|self ...$blocks): self
    {
        return self::join($this, ...$blocks);
    }

    public static function join(string|self ...$blocks): self
    {
        $strings = array_map(static fn (string|self $block): string => (string) $block, $blocks);

        return new self(implode("\n", array_values(array_filter(
            $strings,
            static fn (string $block): bool => '' !== $block,
        ))));
    }

    public function __toString(): string
    {
        return $this->code;
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
