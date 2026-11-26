<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;

final class BooleanCodec extends ColumnCodec
{
    public static function representation(): string
    {
        return 'bool';
    }

    public function normalize(mixed $value): bool
    {
        return is_bool($value) ? $value : throw new \InvalidArgumentException('Expected a boolean.');
    }
}
