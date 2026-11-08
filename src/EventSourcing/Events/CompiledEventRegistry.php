<?php

declare(strict_types=1);

namespace Ultra\Events;

/**
 * @param array<array-key, class-string> $events event name => event class
 */
final readonly class CompiledEventRegistry implements EventRegistryInterface
{
    /**
     * @param array<array-key, class-string>                $events
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
        $name = array_search($eventClass, $this->events, true);

        return false === $name ? null : (string) $name;
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
                // @mago-expect analysis:string-member-selector
                // @mago-expect analysis:ambiguous-object-property-access
                $identifiers[$name] = $event->{$propertyName};
            }
        } else {
            /** @var array<string, mixed> $identifiers */
            $identifiers = \Closure::bind(
                static function () use ($event, $eventClassIdentifiers): array {
                    $identifiers = [];
                    foreach ($eventClassIdentifiers as $name => $propertyName) {
                        // @mago-expect analysis:string-member-selector
                        // @mago-expect analysis:ambiguous-object-property-access
                        $identifiers[$name] = $event->{$propertyName};
                    }

                    return $identifiers;
                },
                null,
                $event::class,
            )();
        }

        $values = [];
        foreach ($eventClassIdentifiers as $name => $propertyName) {
            $value = $identifiers[$name];
            if (!is_string($value) && !$value instanceof \Stringable) {
                throw new \LogicException(sprintf(
                    'Domain identifier property %s::$%s must be string or Stringable, got %s.',
                    $event::class,
                    $propertyName,
                    get_debug_type($value),
                ));
            }
            $values[$name] = (string) $value;
        }

        return $values;
    }

    public function all(): array
    {
        return $this->events;
    }
}
