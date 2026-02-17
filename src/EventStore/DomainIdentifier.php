<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class DomainIdentifier
{
    public function __construct(
        public string $dimension,
        public string $value,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->dimension === $other->dimension && $this->value === $other->value;
    }

    public function key(): string
    {
        return $this->dimension . "\0" . $this->value;
    }
}
