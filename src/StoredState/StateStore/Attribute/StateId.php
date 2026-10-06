<?php

declare(strict_types=1);

namespace Ultra\StateStore\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class StateId
{
    /** @param array<string, string> $columns */
    public function __construct(
        public ?string $column = null,
        public array $columns = [],
    ) {
        if (null !== $column && [] !== $columns) {
            throw new \InvalidArgumentException('StateId cannot declare both column and columns.');
        }
    }
}
