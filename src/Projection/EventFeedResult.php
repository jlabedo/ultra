<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;

final readonly class EventFeedResult
{
    /**
     * @param list<StoredEvent> $events
     */
    public function __construct(
        public array $events,
        public Checkpoint $checkpoint,
    ) {}
}
