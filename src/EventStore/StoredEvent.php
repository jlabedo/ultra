<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class StoredEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $domainIds dimension => value
     * @param array<string, int> $versions dimension => version (one entry per dimension in domainIds)
     */
    public function __construct(
        public string $id,
        public string $eventType,
        public array $payload,
        public array $domainIds,
        public array $versions,
        public int $sequenceNumber,
        public ?\DateTimeImmutable $recordedAt = null,
    ) {
        if ($domainIds === []) {
            throw new \InvalidArgumentException('StoredEvent must have at least one domain identifier.');
        }
        foreach (array_keys($domainIds) as $dimension) {
            if (!\array_key_exists($dimension, $versions)) {
                throw new \InvalidArgumentException(sprintf('StoredEvent must have a version for dimension "%s".', $dimension));
            }
        }
        if (array_keys($domainIds) !== array_keys($versions)) {
            throw new \InvalidArgumentException('StoredEvent versions must have exactly the same dimensions as domainIds.');
        }
    }
}
