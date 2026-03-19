<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

/**
 * Opaque optimistic-concurrency token for a previously loaded query.
 *
 * The token is query-scoped: it stores the last seen event id for each canonical query-stream key derived
 * from the original `StreamQuery`. Missing keys are treated as `0`, which allows empty streams to participate
 * in concurrency checks without requiring pre-existing rows.
 */
readonly class QueryVersion
{
    /**
     * @param array<string, int> $versionByKey canonical query stream key => last eventId
     */
    public function __construct(
        private array $versionByKey,
        public StreamQuery $query,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->versionByKey;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->versionByKey);
    }

    /**
     * Return the last event id seen for a canonical query-stream key.
     *
     * Unknown keys are treated as empty streams and therefore return `0`.
     */
    public function getLastEventId(string $queryStreamKey): int
    {
        return $this->versionByKey[$queryStreamKey] ?? 0;
    }

    /**
     * Compare the current token against a previously observed one.
     *
     * Any difference means the queried snapshot has changed and the caller's decision is now stale.
     *
     * @throws ConcurrencyException
     */
    public function assertMatches(self $expected): void
    {
        $allKeys = array_unique(array_merge(array_keys($this->versionByKey), array_keys($expected->versionByKey)));
        sort($allKeys);
        foreach ($allKeys as $key) {
            $current = $this->versionByKey[$key] ?? 0;
            $expectedEventId = $expected->versionByKey[$key] ?? 0;
            if ($current !== $expectedEventId) {
                throw new ConcurrencyException($key, $expectedEventId, $current);
            }
        }
    }
}
