<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;

final class PageLifetimeEventStore implements EventStore
{
    public int $maxRetainedEvents = 0;

    /** @var list<\WeakReference<Event>> */
    private array $previousPage = [];

    public function __construct(
        private readonly EventStore $inner,
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        $retainedEvents = 0;
        foreach ($this->previousPage as $reference) {
            if (null === $reference->get()) {
                continue;
            }

            ++$retainedEvents;
        }
        $this->maxRetainedEvents = max($this->maxRetainedEvents, $retainedEvents);

        $result = $this->inner->load($query, $from, $to, $batchSize);
        // Memory storage owns its event objects; clones let us observe the reader's page lifetime independently.
        $events = array_map(static fn(Event $event): Event => clone $event, $result->events);
        $this->previousPage = array_map(\WeakReference::create(...), $events);

        return new LoadResult($events, $result->checkpoint, $query, $result->hasMore);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->inner->append($events, ...$appendConditions);
    }
}
