<?php

declare(strict_types=1);

namespace Ultra\Signal;

final readonly class BucketRange
{
    public function __construct(
        public int $lowerInclusive,
        public int $upperExclusive,
    ) {
        if ($lowerInclusive >= $upperExclusive) {
            throw new \InvalidArgumentException('Signal bucket range lower bound must be less than upper bound.');
        }
    }

    public static function single(): self
    {
        return new self(0, 1);
    }

    public static function fromTo(int $lowerInclusive, int $upperExclusive): self
    {
        return new self($lowerInclusive, $upperExclusive);
    }

    public function contains(int $bucket): bool
    {
        return $bucket >= $this->lowerInclusive && $bucket < $this->upperExclusive;
    }
}
