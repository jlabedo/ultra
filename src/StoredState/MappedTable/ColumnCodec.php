<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\Sql\SqlType;

/** Converts non-null values for a SQL column. Instances can be shared across columns and buffers. */
abstract class ColumnCodec
{
    /** @return 'int'|'string'|'float'|'bool'|'array'|'JSON' */
    abstract public static function representation(): string;

    public static function supportsKeys(): bool
    {
        return false;
    }

    /** Accept application input or an already normalized value. */
    abstract public function normalize(SqlType $type, mixed $value): int|string|float|bool|array|\stdClass|JsonNull;

    /** Return a stable int or string for every spelling of the same database key. */
    public function key(SqlType $type, mixed $value): int|string
    {
        $key = $this->normalize($type, $value);

        return is_int($key) || is_string($key)
            ? $key
            : throw new \InvalidArgumentException('Mapped key values must normalize to an int or a string.');
    }

    /** Compare normalized values in the buffer and in the in-memory adapter. */
    public function equivalent(SqlType $type, mixed $left, mixed $right): bool
    {
        return $left === $right;
    }

    /** Encode a normalized value as a SQL parameter. */
    public function encode(SqlType $type, mixed $value): int|string|float|bool
    {
        return is_scalar($value)
            ? $value
            : throw new \InvalidArgumentException('A non-scalar column value requires explicit parameter encoding.');
    }

    /** Decode a value returned by the SQL driver. */
    public function decode(SqlType $type, mixed $value): int|string|float|bool|array|\stdClass|JsonNull
    {
        return $this->normalize($type, $value);
    }
}
