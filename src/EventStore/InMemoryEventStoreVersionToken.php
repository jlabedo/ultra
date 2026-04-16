<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * @internal
 */
final readonly class InMemoryEventStoreVersionToken implements VersionToken
{
    public function __construct(
        private int $matchingEventCount,
    ) {}

    public function matchingEventCount(): int
    {
        return $this->matchingEventCount;
    }

    public function matches(VersionToken $expected): bool
    {
        return $expected instanceof self
            && $this->matchingEventCount === $expected->matchingEventCount;
    }
}
