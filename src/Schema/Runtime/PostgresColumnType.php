<?php

declare(strict_types=1);

namespace Ultra\Schema;

/** PostgreSQL spellings understood by Schema and its consumers. */
final class PostgresColumnType
{
    public static function sql(ColumnType|string $type): string
    {
        return match ($type) {
            ColumnType::Text => 'text',
            ColumnType::Int32 => 'integer',
            ColumnType::Int64 => 'bigint',
            ColumnType::Boolean => 'boolean',
            default => $type,
        };
    }

    /** Recognize supported aliases while retaining modifiers that affect the column's SQL type. */
    public static function canonical(ColumnType|string $type): ?string
    {
        $sql = preg_replace(['/\s+/', '/ *\[ *\]$/'], [' ', '[]'], strtolower(trim(self::sql($type))));
        assert(null !== $sql, 'The type whitespace patterns are valid.');

        $parts = [];
        if (
            1 === preg_match(
                '/^(timestamp|timestamptz)(?: *\( *0*([0-6]) *\))?( with(?:out)? time zone)?$/D',
                $sql,
                $parts,
            )
        ) {
            $withZone = 'timestamptz' === $parts[1];
            if ($withZone && isset($parts[3])) {
                return null;
            }
            $withZone = $withZone || ' with time zone' === ($parts[3] ?? '');

            return (
                'timestamp('
                . ('' === ($parts[2] ?? '') ? '6' : $parts[2])
                . ') '
                . ($withZone ? 'with' : 'without')
                . ' time zone'
            );
        }

        return match ($sql) {
            'integer', 'int', 'int4' => 'integer',
            'bigint', 'int8' => 'bigint',
            'boolean', 'bool' => 'boolean',
            'integer[]', 'int[]', 'int4[]' => 'integer[]',
            'text', 'uuid', 'date', 'jsonb', 'text[]', 'uuid[]' => $sql,
            default => null,
        };
    }

    public static function equivalent(ColumnType|string $left, ColumnType|string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $canonical = self::canonical($left);

        return null !== $canonical && $canonical === self::canonical($right);
    }
}
