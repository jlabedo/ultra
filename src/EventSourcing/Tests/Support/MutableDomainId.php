<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

final class MutableDomainId implements \Stringable
{
    public function __construct(
        public string $value,
    ) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
