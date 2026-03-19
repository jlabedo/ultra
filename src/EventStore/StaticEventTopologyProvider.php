<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * In-memory topology registry keyed by event type.
 */
readonly class StaticEventTopologyProvider implements EventTopologyProvider
{
    /**
     * @param array<string, EventTopology> $topologies
     */
    public function __construct(
        private array $topologies,
    ) {}

    public function get(string $eventType): ?EventTopology
    {
        return $this->topologies[$eventType] ?? null;
    }
}
