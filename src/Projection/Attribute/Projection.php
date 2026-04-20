<?php

declare(strict_types=1);

namespace Ultra\Projection\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Projection
{
    public function __construct(
        public string $name,
        public string $partitionBy,
    ) {
        if ('' === $this->name) {
            throw new \InvalidArgumentException('Projection name must be a non-empty string.');
        }

        if ('' === $this->partitionBy) {
            throw new \InvalidArgumentException('Projection partitionBy must be a non-empty string.');
        }
    }
}
