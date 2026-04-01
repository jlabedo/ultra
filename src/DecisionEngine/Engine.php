<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Ultra\DecisionEngine\Exception\UnknownEventSourcedEntityException;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\EventStore;

final readonly class Engine
{
    /**
     * @param array<class-string, EventSourcedEntityLoader> $loaders
     */
    public function __construct(
        private array $loaders,
        private ?EventStore $eventStore = null,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $entityClass
     * @return T
     */
    public function load(string $entityClass, DomainIdentifier ...$identifiers): object
    {
        $loader = $this->loader($entityClass);

        if (! $this->eventStore instanceof EventStore) {
            throw new \LogicException(sprintf(
                'Engine cannot load %s because no event store was configured.',
                $entityClass,
            ));
        }

        $events = $this->eventStore->load($loader->query(...$identifiers))->events;

        return $loader->hydrate($events, ...$identifiers);
    }

    /**
     * @param class-string $entityClass
     */
    public function loader(string $entityClass): EventSourcedEntityLoader
    {
        $loader = $this->loaders[$entityClass] ?? null;
        if (! $loader instanceof EventSourcedEntityLoader) {
            throw new UnknownEventSourcedEntityException($entityClass);
        }

        return $loader;
    }
}
