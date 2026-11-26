<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Codec;

use Ultra\MappedTable\ColumnCodec;

final class IntegerCodec extends ColumnCodec
{
    public function __construct(
        public readonly bool $int32 = false,
    ) {}

    public static function representation(): string
    {
        return 'int';
    }

    public static function supportsKeys(): bool
    {
        return true;
    }

    public function normalize(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Expected an integer.');
        }
        if ($this->int32 && ($value < -2_147_483_648 || $value > 2_147_483_647)) {
            throw new \InvalidArgumentException('Mapped integer is outside the PostgreSQL integer range.');
        }

        return $value;
    }
}
