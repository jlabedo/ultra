<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Value object identifying one dimension of a stream: (dimension, value).
 * A stream is (DomainIdentifier, eventType); an event can belong to multiple streams.
 */
final readonly class DomainIdentifier
{
    public function __construct(
        public string $dimension,
        public string $value,
    ) {
    }

    /** @return array<string, string> */
    public function toMap(): array
    {
        return [$this->dimension => $this->value];
    }
}
