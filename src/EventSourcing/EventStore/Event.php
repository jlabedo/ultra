<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class Event
{
    /**
     * @param array<array-key, mixed>  $payload
     * @param array<string, string> $domainIds dimension => value
     * @param array<array-key, mixed>  $metadata
     */
    public function __construct(
        public string $eventType,
        public array $payload,
        public array $domainIds,
        public ?\DateTimeImmutable $recordedAt = null,
        public array $metadata = [],
    ) {
        if ([] === $domainIds) {
            throw new \InvalidArgumentException('Event must have at least one domain identifier.');
        }
    }
}
