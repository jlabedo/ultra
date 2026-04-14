<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\UnsupportedQueryException;

/**
 * Validates typed stream queries and turns them into optimistic-concurrency lock keys.
 *
 * Query validation is deliberately strict: each branch must be typed and may contain at most two identifiers.
 * The same factory is also used when deriving appended-event lock keys so in-memory and persisted stores
 * agree on the exact optimistic-concurrency key set.
 */
final readonly class CanonicalQueryKeyFactory
{
    /**
     * Resolve a user-facing `StreamQuery` into a validated internal form.
     *
     * @throws UnsupportedQueryException when a branch is untyped or too wide
     */
    public function resolveQuery(StreamQuery $query): CanonicalQuery
    {
        if ($query->isEmpty()) {
            return new CanonicalQuery($query, []);
        }

        $branches = [];
        foreach ($this->branchesFromQuery($query) as [$criteria, $eventTypes]) {
            if ([] === $eventTypes) {
                throw new UnsupportedQueryException('Typed queries are required; each query branch must specify at least one event type.');
            }

            if (count($criteria) > 2) {
                throw new UnsupportedQueryException('Query branches may contain at most two domain identifiers.');
            }

            $keys = [];
            foreach ($eventTypes as $eventType) {
                $keys[] = $this->buildKey($eventType, $criteria);
            }

            $branches[] = new CanonicalQueryBranch(
                domainIdentifiers: $criteria,
                eventTypes: array_values($eventTypes),
                keys: array_values(array_unique($keys)),
            );
        }

        return new CanonicalQuery($query, $branches);
    }

    /**
     * @param array<string, string> $domainIds
     *
     * @return list<string>
     */
    public function keysForEvent(string $eventType, array $domainIds): array
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

    /**
     * @return list<array{0: array<string, string>, 1: list<string>}>
     *
     * Flatten the two `StreamQuery` shapes (`forStream()` and `anyOf()`) into a branch list
     */
    private function branchesFromQuery(StreamQuery $query): array
    {
        if ([] !== $query->orCriteria) {
            $eventTypesByBranch = $query->orBranchEventNames ?? array_fill(0, count($query->orCriteria), $query->eventNames);
            $branches = [];
            foreach ($query->orCriteria as $index => $criteria) {
                $branches[] = [$criteria, array_values($eventTypesByBranch[$index] ?? $query->eventNames)];
            }

            return $branches;
        }

        if ([] === $query->domainIdentifiers) {
            return [];
        }

        return [[
            $query->domainIdentifiers,
            array_values($query->eventNames),
        ]];
    }
}
