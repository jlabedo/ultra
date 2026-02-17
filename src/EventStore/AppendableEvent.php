<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class AppendableEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $domainIds dimension => value
     * @param array<string, int> $expectedVersions dimension => expected version (one entry per dimension in domainIds)
     */
    public function __construct(
        public string $id,
        public string $eventType,
        public array $payload,
        public array $domainIds,
        public array $expectedVersions,
    ) {
        if ($domainIds === []) {
            throw new \InvalidArgumentException('AppendableEvent must have at least one domain identifier.');
        }
        foreach (array_keys($domainIds) as $dimension) {
            if (!\array_key_exists($dimension, $expectedVersions)) {
                throw new \InvalidArgumentException(sprintf('AppendableEvent must have an expected version for dimension "%s".', $dimension));
            }
        }
        if (array_keys($domainIds) !== array_keys($expectedVersions)) {
            throw new \InvalidArgumentException('AppendableEvent expectedVersions must have exactly the same dimensions as domainIds.');
        }
    }
}
