<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\Projection\StoredEvent;

abstract class PartitionStrategy
{
    public static function single(): self
    {
        return new SinglePartitionStrategy();
    }

    public static function byDomainId(string $dimension): self
    {
        return new DomainIdPartitionStrategy([$dimension]);
    }

    public static function byDomainIds(string ...$dimensions): self
    {
        return new DomainIdPartitionStrategy($dimensions);
    }

    /**
     * @return list<string>
     */
    abstract public function dimensions(): array;

    abstract public function keyFor(StoredEvent $event): string;
}
