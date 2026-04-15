<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\UnsupportedQueryException;

/**
 * Builds the optimistic-concurrency lock keys implied by stream queries and appended events.
 *
 * Query validation is deliberately strict: each branch must be typed and may contain at most two identifiers.
 * Write keys include every singleton and pair so a write can conflict with every supported read shape.
 */
final readonly class StreamQueryLockKeys
{
    /**
     * @throws UnsupportedQueryException when a branch is untyped or too wide
     */
    public function assertQuerySupported(StreamQuery $query): void
    {
        foreach ($query->branches as $branch) {
            if ([] === $branch->eventTypes) {
                throw new UnsupportedQueryException('Typed queries are required; each query branch must specify at least one event type.');
            }

            if (count($branch->domainIdentifiers) > 2) {
                throw new UnsupportedQueryException('Query branches may contain at most two domain identifiers.');
            }
        }
    }

    /**
     * @return list<string>
     *
     * @throws UnsupportedQueryException when a branch is untyped or too wide
     */
    public function readKeys(StreamQuery $query): array
    {
        $this->assertQuerySupported($query);

        $keys = [];
        foreach ($query->branches as $branch) {
            foreach ($branch->eventTypes as $eventType) {
                $keys[$this->buildKey($eventType, $branch->domainIdentifiers)] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, string> $domainIds
     *
     * @return list<string>
     */
    public function writeKeysForEvent(string $eventType, array $domainIds): array
    {
        $dimensions = array_keys($domainIds);
        sort($dimensions);
        $keys = [];
        foreach ($dimensions as $dimension) {
            $keys[$this->buildKey($eventType, [$dimension => $domainIds[$dimension]])] = true;
        }

        $count = count($dimensions);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $criteria = [
                    $dimensions[$i] => $domainIds[$dimensions[$i]],
                    $dimensions[$j] => $domainIds[$dimensions[$j]],
                ];
                $keys[$this->buildKey($eventType, $criteria)] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, string> $criteria
     *
     * Build the opaque lock key for a typed query branch.
     */
    public function buildKey(string $eventType, array $criteria): string
    {
        ksort($criteria);

        return json_encode([$eventType, $criteria], JSON_THROW_ON_ERROR);
    }
}
