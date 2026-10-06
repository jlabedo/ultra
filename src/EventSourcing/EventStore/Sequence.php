<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class Sequence
{
    public function __construct(
        public int $value,
    ) {
        if ($value < 0) {
            throw new \InvalidArgumentException('Sequence must be zero or positive.');
        }
    }

    public static function beginning(): self
    {
        return new self(0);
    }

    public function compareTo(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function isAtOrBefore(self $other): bool
    {
        return $this->value <= $other->value;
    }
}
