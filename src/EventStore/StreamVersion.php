<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class StreamVersion
{
    /**
     * @param array<string, int> $version streamId (StreamId::toString()) => last eventId in that stream
     */
    public function __construct(
        public array $version = [],
    ) {
    }

    public function getLastEventId(StreamId $streamId): int
    {
        return $this->version[$streamId->toString()] ?? 0;
    }
}
