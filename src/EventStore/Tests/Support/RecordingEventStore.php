<?php

declare(strict_types=1);

namespace Ultra\EventStore\Tests\Support;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;

final class RecordingEventStore implements EventStore
{
    /** @var list<array{events: list<Event>, appendConditions: list<AppendCondition>}> */
    public array $appendCalls = [];

    public function __construct(private readonly EventStore $inner) {}

    public function load(StreamQuery $query, ?Checkpoint $from = null, ?Checkpoint $to = null, int $batchSize = 1000): LoadResult
    {
        return $this->inner->load($query, $from, $to, $batchSize);
    }

    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->appendCalls[] = ['events' => $events, 'appendConditions' => $appendConditions];
        $this->inner->append($events, ...$appendConditions);
    }
}
