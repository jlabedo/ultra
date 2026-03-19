<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class StoredEvent
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $domainIds dimension => value
     */
    public function __construct(
        public int $eventId,
        public string $eventType,
        public array $payload,
        public array $domainIds,
        public ?\DateTimeImmutable $recordedAt = null,
    ) {
        if ([] === $domainIds) {
            throw new \InvalidArgumentException('StoredEvent must have at least one domain identifier.');
        }
    }
}
