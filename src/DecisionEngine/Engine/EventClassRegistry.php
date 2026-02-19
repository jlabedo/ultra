<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

/**
 * Maps event type names (from #[Event]) to event DTO class names.
 * Used when hydrating state from stored events.
 */
interface EventClassRegistry
{
    public function getClass(string $eventType): ?string;
}
