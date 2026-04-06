<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Event;

readonly class StoredEvent extends Event
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $domainIds dimension => value
     */
    public function __construct(
        public int $eventId,
        string $eventType,
        array $payload,
        array $domainIds,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        parent::__construct($eventType, $payload, $domainIds, $recordedAt);
    }
}
