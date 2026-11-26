<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;
use Ultra\Sql\SqlType;

/** @internal */
final class UuidCodec extends ColumnCodec
{
    public static function representation(): string
    {
        return 'string';
    }

    public static function supportsKeys(): bool
    {
        return true;
    }

    public function normalize(SqlType $type, mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException('Expected a UUID string or Stringable.'),
        };
    }

    public function key(SqlType $type, mixed $value): string
    {
        $key = self::canonical($this->normalize($type, $value));

        return $key ?? throw new \InvalidArgumentException('Expected a valid UUID key.');
    }

    public function equivalent(SqlType $type, mixed $left, mixed $right): bool
    {
        return (
            $left === $right
            || is_string($left)
            && is_string($right)
            && null !== ($canonical = self::canonical($left))
            && $canonical === self::canonical($right)
        );
    }

    public static function canonical(string $value): ?string
    {
        $pattern = '/^(?:[0-9a-f]{4}-?){7}[0-9a-f]{4}$/iD';
        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $value = substr($value, 1, -1);
        }
        if (1 !== preg_match($pattern, $value)) {
            return null;
        }

        $digits = strtolower(str_replace('-', '', $value));

        return (
            substr($digits, 0, 8)
            . '-'
            . substr($digits, 8, 4)
            . '-'
            . substr($digits, 12, 4)
            . '-'
            . substr($digits, 16, 4)
            . '-'
            . substr($digits, 20)
        );
    }
}
