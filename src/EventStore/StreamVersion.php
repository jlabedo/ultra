<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

readonly class StreamVersion
{
    /**
     * @param array<string, int> $version streamId (StreamId::toString()) => last eventId in that stream
     */
    public function __construct(
        public array $version,
        public StreamQuery $query,
    ) {
    }

    public function getLastEventId(StreamId $streamId): int
    {
        return $this->version[$streamId->toString()] ?? 0;
    }

    public function assertMatches(self $expected): void
    {
        $allKeys = array_unique(array_merge(array_keys($this->version), array_keys($expected->version)));
        foreach ($allKeys as $key) {
            $current = $this->version[$key] ?? 0;
            $expectedEventId = $expected->version[$key] ?? 0;
            if ($current !== $expectedEventId) {
                throw new ConcurrencyException($key, $expectedEventId, $current);
            }
        }
    }
}
