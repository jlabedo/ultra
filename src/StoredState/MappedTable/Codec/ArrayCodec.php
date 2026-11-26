<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;

/** PostgreSQL one-dimensional arrays. */
final class ArrayCodec extends ColumnCodec
{
    public function __construct(public readonly TextCodec|IntegerCodec|UuidCodec $element = new TextCodec()) {}

    public static function representation(): string
    {
        return 'array';
    }

    /** @return list<string|int|null> */
    public function normalize(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('A mapped array expects a PHP list.');
        }

        return array_map(fn(mixed $item): string|int|null => null === $item
            ? null
            : $this->element->normalize($item), $value);
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }

        return (
            $this->element instanceof UuidCodec
            && is_array($left)
            && is_array($right)
            && array_map(self::uuidElement(...), $left) === array_map(self::uuidElement(...), $right)
        );
    }

    public function encode(mixed $value): string
    {
        $encoded = array_map(
            static fn(string|int|null $item): string => match (true) {
                null === $item => 'NULL',
                is_int($item) => (string) $item,
                default => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '"',
            },
            $this->normalize($value),
        );

        return '{' . implode(',', $encoded) . '}';
    }

    /** @return list<string|int|null> */
    public function decode(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('A PostgreSQL array expects a database string.');
        }
        $items = self::decodeArray($value);

        return $this->element instanceof IntegerCodec
            ? array_map(static fn(?string $item): ?int => null === $item ? null : (int) $item, $items)
            : $this->normalize($items);
    }

    private static function uuidElement(?string $value): ?string
    {
        return null === $value ? null : UuidCodec::canonical($value) ?? $value;
    }

    /** @return list<string|null> */
    private static function decodeArray(string $value): array
    {
        if ('{}' === $value) {
            return [];
        }

        if (!str_starts_with($value, '{') || !str_ends_with($value, '}')) {
            throw new \InvalidArgumentException(sprintf('Cannot decode Postgres array literal "%s".', $value));
        }

        $items = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $wasQuoted = false;
        $inner = substr($value, 1, -1);

        for ($i = 0, $length = strlen($inner); $i < $length; ++$i) {
            $char = $inner[$i];

            if ($escaped) {
                $buffer .= $char;
                $escaped = false;

                continue;
            }

            if ($quoted && '\\' === $char) {
                $escaped = true;

                continue;
            }

            if ('"' === $char) {
                $quoted = !$quoted;
                $wasQuoted = true;

                continue;
            }

            if (!$quoted && ('{' === $char || '}' === $char)) {
                throw new \InvalidArgumentException('Mapped PostgreSQL arrays must be one-dimensional.');
            }
            if (!$quoted && ',' === $char) {
                $items[] = !$wasQuoted && 'NULL' === $buffer ? null : $buffer;
                $buffer = '';
                $wasQuoted = false;

                continue;
            }

            $buffer .= $char;
        }

        if ($quoted || $escaped) {
            throw new \InvalidArgumentException(sprintf('Cannot decode Postgres array literal "%s".', $value));
        }

        $items[] = !$wasQuoted && 'NULL' === $buffer ? null : $buffer;

        return $items;
    }
}
