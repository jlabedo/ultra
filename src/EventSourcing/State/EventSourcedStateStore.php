<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState;

use Ultra\EventSourcedState\Exception\UnknownEventSourcedEntityException;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\DomainIdentifier;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\StreamQuery;

final readonly class EventSourcedStateStore
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
     *
     * @param class-string<T> $entityClass
     *
     * @return T
     */
    public function load(string $entityClass, DomainIdentifier ...$identifiers): object
    {
        $loader = $this->loader($entityClass);

        if (!$this->eventStore instanceof EventStore) {
            throw new \LogicException(sprintf(
                'EventSourcedStateStore cannot load %s because no event store was configured.',
                $entityClass,
            ));
        }

        $events = $this->loadEvents($loader->query(...$identifiers));

        return $loader->hydrate($events, ...$identifiers);
    }

    /**
     * @param class-string $entityClass
     */
    public function loader(string $entityClass): EventSourcedEntityLoader
    {
        $loader = $this->loaders[$entityClass] ?? null;
        if (!$loader instanceof EventSourcedEntityLoader) {
            throw new UnknownEventSourcedEntityException($entityClass);
        }

        return $loader;
    }

    /** @return \Generator<int, Event> */
    private function loadEvents(StreamQuery $query): \Generator
    {
        $checkpoint = Checkpoint::beginning();

        while (true) {
            $result = $this->eventStore->load($query, from: $checkpoint);
            yield from $result->events;

            if (!$result->hasMore) {
                return;
            }

            $checkpoint = $result->checkpoint;
            unset($result);
        }
    }
}
