<?php

declare(strict_types=1);

namespace Ultra\Mapper;

/**
 * @internal
 */
readonly class Variable
{
    public function __construct(
        private string $name,
    ) {}

    public function __toString(): string
    {
        if (str_starts_with($this->name, '$')) {
            return $this->name;
        }

        return '$' . $this->name;
    }
}
