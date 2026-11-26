<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;
use Ultra\MappedTable\JsonNull;
use Ultra\MappedTable\Storage\JsonbValue;

final class JsonbCodec extends ColumnCodec
{
    public static function representation(): string
    {
        return 'JSON';
    }

    public function normalize(mixed $value): int|string|float|bool|array|\stdClass|JsonNull
    {
        return JsonbValue::copy($value);
    }

    public function equivalent(mixed $left, mixed $right): bool
    {
        return $left === $right || JsonbValue::equivalent($left, $right);
    }

    public function encode(mixed $value): string
    {
        return JsonbValue::encode($value);
    }

    public function decodesDetachedValues(): bool
    {
        return true;
    }

    public function decode(mixed $value): int|string|float|bool|array|\stdClass|JsonNull
    {
        return is_string($value)
            ? JsonbValue::decode($value)
            : throw new \InvalidArgumentException('PostgreSQL jsonb expects a database string.');
    }
}
