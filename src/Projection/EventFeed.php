<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\StoredEvent;

interface EventFeed
{
    /**
     * @param list<string> $eventTypes
     *
     * @return list<StoredEvent>
     */
    public function readAfter(int $afterEventId, int $limit, array $eventTypes = []): array;

    /**
     * @param list<int> $eventIds
     *
     * @return list<StoredEvent>
     */
    public function readByIds(array $eventIds): array;
}
