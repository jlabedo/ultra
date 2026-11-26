<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Codec\ArrayCodec;
use Ultra\MappedTable\Codec\BooleanCodec;
use Ultra\MappedTable\Codec\IntegerCodec;
use Ultra\MappedTable\Codec\JsonbCodec;
use Ultra\MappedTable\Codec\TemporalCodec;
use Ultra\MappedTable\Codec\TextCodec;
use Ultra\MappedTable\Codec\UuidCodec;
use Ultra\Sql\ColumnType;
use Ultra\Sql\SqlType;

/** @internal Built-in associations and canonical registration keys. */
final class ColumnCodecs
{
    public static function builtin(SqlType $type): ?ColumnCodec
    {
        return match ($type->family) {
            ColumnType::Text, ColumnType::Varchar => new TextCodec(),
            ColumnType::Int32, ColumnType::Int64 => new IntegerCodec(),
            ColumnType::Boolean => new BooleanCodec(),
            ColumnType::Uuid => new UuidCodec(),
            ColumnType::Date, ColumnType::Timestamp, ColumnType::TimestampWithTimeZone => new TemporalCodec(),
            ColumnType::TextArray, ColumnType::UuidArray, ColumnType::IntArray => new ArrayCodec(),
            ColumnType::Jsonb => new JsonbCodec(),
            null => null,
        };
    }

    /** @param array<string, mixed> $registered */
    public static function registrationKey(string $sqlType, array $registered): string
    {
        $key = SqlType::fromSql($sqlType)->sql;
        if (isset($registered[$key])) {
            throw new \InvalidArgumentException(sprintf('Multiple codecs registered for SQL type "%s".', $key));
        }

        return $key;
    }
}
