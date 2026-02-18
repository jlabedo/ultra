<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class AppendableEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $domainIds dimension => value
     */
    public function __construct(
        public string $id,
        public string $eventType,
        public array $payload,
        public array $domainIds,
    ) {
        if ($domainIds === []) {
            throw new \InvalidArgumentException('AppendableEvent must have at least one domain identifier.');
        }
    }
}
