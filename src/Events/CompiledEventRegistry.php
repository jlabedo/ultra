<?php

declare(strict_types=1);

namespace Ultra\Events;

/**
 * @param array<string, class-string> $events event name => event class
 */
final readonly class CompiledEventRegistry implements EventRegistryInterface
{
    /**
     * @param array<string, class-string> $events
     */
    public function __construct(
        private array $events,
    ) {}

    public function getClass(string $eventName): ?string
    {
        return $this->events[$eventName] ?? null;
    }

    public function getName(string $eventClass): ?string
    {
        return array_search($eventClass, $this->events, true) ?: null;
    }

    public function all(): array
    {
        return $this->events;
    }
}
