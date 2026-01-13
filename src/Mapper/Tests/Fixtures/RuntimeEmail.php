<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

readonly class RuntimeEmail implements \Stringable
{
    public function __construct(
        public string $value
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
