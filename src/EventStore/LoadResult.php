<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Result of loading a stream query.
 */
readonly class LoadResult
{
    /**
     * @param list<Event> $events events matching the query, ordered by sequence ascending
     */
    public function __construct(
        public array $events,
        public Checkpoint $checkpoint,
        public StreamQuery $baseQuery,
    ) {}

    public function appendCondition(): AppendCondition
    {
        return new AppendCondition($this->checkpoint, $this->baseQuery);
    }
}
