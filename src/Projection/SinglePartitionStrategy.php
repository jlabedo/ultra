<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\StoredEvent;

final class SinglePartitionStrategy extends PartitionStrategy
{
    public function dimensions(): array
    {
        return [];
    }

    public function keyFor(StoredEvent $event): string
    {
        return '__single__';
    }
}
