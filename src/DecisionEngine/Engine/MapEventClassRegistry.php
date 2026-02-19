<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine\Engine;

/**
 * Simple registry that maps event type names to class names.
 *
 * @param array<string, string> $map eventType => class name
 */
final readonly class MapEventClassRegistry implements EventClassRegistry
{
    public function __construct(
        private array $map = [],
    ) {
    }

    public function getClass(string $eventType): ?string
    {
        return $this->map[$eventType] ?? null;
    }
}
