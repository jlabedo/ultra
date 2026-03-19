<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Resolves topology metadata for an event type.
 *
 * Implementations are responsible for telling the event store which identifiers exist on an event and which
 * unary dependencies allow non-canonical pairs to be rejected or reduced during event indexing.
 */
interface EventTopologyProvider
{
    /**
     * Return topology metadata for an event type, or `null` when that event type is not supported.
     */
    public function get(string $eventType): ?EventTopology;
}
