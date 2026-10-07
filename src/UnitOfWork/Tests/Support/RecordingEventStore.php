<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Tests\Support;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;

final class RecordingEventStore implements EventStore
{
    /** @var list<list<Event>> */
    public array $appendCalls = [];

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return new LoadResult([], $to ?? $from ?? Checkpoint::beginning(), $query);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->appendCalls[] = $events;
    }
}
