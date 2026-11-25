<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;
use Ultra\Schema\ColumnType;
use Ultra\Schema\SqlType;

/** @internal */
final class TemporalCodec extends ColumnCodec
{
    public static function representation(): string
    {
        return 'string';
    }

    public function normalize(SqlType $type, mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(match ($type->family) {
                ColumnType::Date => 'Y-m-d',
                ColumnType::Timestamp => 'Y-m-d H:i:s.u',
                default => 'Y-m-d H:i:s.uP',
            });
        }

        return is_string($value)
            ? $value
            : throw new \InvalidArgumentException('Expected a date string or DateTimeInterface.');
    }

    public function equivalent(SqlType $type, mixed $left, mixed $right): bool
    {
        return (
            $left === $right
            || ColumnType::Date !== $type->family
            && is_string($left)
            && is_string($right)
            && null !== ($canonical = self::comparisonString($type->family, $left))
            && $canonical === self::comparisonString($type->family, $right)
        );
    }

    private static function comparisonString(?ColumnType $family, string $value): ?string
    {
        // Recognize ISO timestamps, including the offset spellings PostgreSQL returns in ISO DateStyle.
        $parts = [];
        $pattern = '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-5])(?::?[0-5]\d)?(?::[0-5]\d)?)?$/D';
        if (1 !== preg_match($pattern, $value, $parts)) {
            return null;
        }

        $local = $parts[1] . ' ' . $parts[2] . '.' . str_pad($parts[3] ?? '', 6, '0');
        if (ColumnType::Timestamp === $family) {
            return $local;
        }
        if (!isset($parts[4])) {
            return null;
        }

        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $local . $parts[4]);

        return false !== $instant && false === \DateTimeImmutable::getLastErrors() ? $instant->format('U.u') : null;
    }
}
