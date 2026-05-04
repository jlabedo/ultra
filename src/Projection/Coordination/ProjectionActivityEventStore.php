<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;

final readonly class ProjectionActivityEventStore implements EventStore
{
    public function __construct(
        private EventStore $inner,
        private ProjectionActivityRecorder $activityRecorder,
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return $this->inner->load($query, $from, $to, $batchSize);
    }

    /** @param list<Event> $events */
    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->inner->append($events, ...$appendConditions);

        if ([] !== $events) {
            $this->activityRecorder->record($events);
        }
    }
}
