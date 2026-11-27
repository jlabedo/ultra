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

    /** @internal Check the range before PHP promotes overflowing arithmetic to float. */
    public static function add(mixed $value, int $delta): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('An increment requires an integer base value.');
        }
        if ($delta > 0 && $value > (PHP_INT_MAX - $delta) || $delta < 0 && $value < (PHP_INT_MIN - $delta)) {
            throw new \OverflowException('Mapped integer increment is outside the PHP integer range.');
        }

        return $value + $delta;
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
