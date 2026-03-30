<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine;

use Ultra\DecisionEngine\Exception\UnknownEventSourcedEntityException;
use Ultra\EventStore\DomainIdentifier;

final readonly class Engine
{
    /**
     * @param array<class-string, EventSourcedEntityLoader> $loaders
     */
    public function __construct(
        private array $loaders,
    ) {}

    /**
     * @param class-string $entityClass
     */
    public function load(string $entityClass, DomainIdentifier ...$identifiers): object
    {
        $loader = $this->loaders[$entityClass] ?? null;
        if (! $loader instanceof EventSourcedEntityLoader) {
            throw new UnknownEventSourcedEntityException($entityClass);
        }

        return $loader->load(...$identifiers);
    }
}
