<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class LoadResult
{
    /**
     * @param list<StoredEvent> $events        events matching the query, ordered by eventId ascending
     * @param StreamVersion     $streamVersion for each stream present in the result, the last eventId in that stream
     */
    public function __construct(
        public array $events,
        public StreamVersion $streamVersion,
    ) {}
}
