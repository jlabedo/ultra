<?php

declare(strict_types=1);

namespace Ultra\Projection;

readonly class ProjectionEnvelope
{
    /**
     * @param array<string, string> $domainIds
     */
    public function __construct(
        public int $eventId,
        public string $eventType,
        public object $event,
        public array $domainIds,
        public ?\DateTimeImmutable $recordedAt = null,
    ) {}
}
