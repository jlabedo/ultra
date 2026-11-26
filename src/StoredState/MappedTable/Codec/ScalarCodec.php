<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;

/** @internal Pass through scalar values for columns without a configured codec. */
final class ScalarCodec extends ColumnCodec
{
    public static function supportsKeys(): bool
    {
        return true;
    }

    public function normalize(mixed $value): int|string|float|bool
    {
        return is_scalar($value)
            ? $value
            : throw new \InvalidArgumentException('A non-scalar mapped value needs a codec.');
    }

    public function key(mixed $value): int|string
    {
        return parent::key($value instanceof \Stringable ? (string) $value : $value);
    }
}
