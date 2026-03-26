<?php

declare(strict_types=1);

namespace Ultra\Projection\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Projection
{
    public function __construct(
        public string $name,
        public int $version = 1,
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('Projection names must be non-empty strings.');
        }

        if ($this->version < 1) {
            throw new \InvalidArgumentException('Projection versions must be greater than or equal to 1.');
        }
    }
}
