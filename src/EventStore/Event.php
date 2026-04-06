<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class Event
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $domainIds dimension => value
     */
    public function __construct(
        public string $eventType,
        public array $payload,
        public array $domainIds,
        public ?\DateTimeImmutable $recordedAt = null,
    ) {
        if ([] === $domainIds) {
            throw new \InvalidArgumentException('Event must have at least one domain identifier.');
        }
    }
}
