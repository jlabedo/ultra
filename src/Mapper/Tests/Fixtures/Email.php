<?php

declare(strict_types=1);

namespace Ultra\Mapper\Tests\Fixtures;

use Ultra\Mapper\Attribute\AsValue;

#[AsValue]
readonly class Email implements \Stringable
{
    public function __construct(
        public string $value
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
