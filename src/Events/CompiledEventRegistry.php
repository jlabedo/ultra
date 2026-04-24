<?php

declare(strict_types=1);

namespace Ultra\Events;

/**
 * @param array<string, class-string> $events event name => event class
 */
final readonly class CompiledEventRegistry implements EventRegistryInterface
{
    /**
     * @param array<string, class-string>                $events
     * @param array<class-string, array<string, string>> $domainIdentifiers
     * @param array<class-string, bool>                  $domainIdentifierRequiresScope
     */
    public function __construct(
        private array $events,
        private array $domainIdentifiers = [],
        private array $domainIdentifierRequiresScope = [],
    ) {}

    public function getClass(string $eventName): ?string
    {
        return $this->events[$eventName] ?? null;
    }

    public function getName(string $eventClass): ?string
    {
        return array_search($eventClass, $this->events, true) ?: null;
    }

    public function getDomainIdentifiers(object $event): array
    {
        $eventClassIdentifiers = $this->domainIdentifiers[$event::class] ?? [];
        if ([] === $eventClassIdentifiers) {
            return [];
        }

        if (!($this->domainIdentifierRequiresScope[$event::class] ?? false)) {
            $identifiers = [];
            foreach ($eventClassIdentifiers as $name => $propertyName) {
                $identifiers[$name] = $event->{$propertyName};
            }

            return $identifiers;
        }

        return (\Closure::bind(
            static function () use ($event, $eventClassIdentifiers): array {
                $identifiers = [];
                foreach ($eventClassIdentifiers as $name => $propertyName) {
                    $identifiers[$name] = $event->{$propertyName};
                }

                return $identifiers;
            },
            null,
            $event::class,
        ))();
    }

    public function all(): array
    {
        return $this->events;
    }
}
