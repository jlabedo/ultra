<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres\LockStrategy;

use Ultra\EventStore\StreamQuery;

/**
 * Builds the advisory-lock namespaces implied by stream queries and appended events.
 *
 * Untyped branches use a wildcard event type key. Writes generate both typed and wildcard keys for every
 * non-empty domain-identifier subset so arbitrary-width queries can conflict with matching writes.
 */
final readonly class StreamQueryLockKeys
{
    /**
     * @return list<string>
     */
    public function readKeys(StreamQuery $query): array
    {
        $keys = [];
        foreach ($query->branches as $branch) {
            $eventTypes = [] === $branch->eventTypes ? [null] : $branch->eventTypes;
            foreach ($eventTypes as $eventType) {
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
        $keys = [];
        foreach ($this->domainIdentifierSubsets($domainIds) as $criteria) {
            $keys[$this->buildKey(null, $criteria)] = true;
            $keys[$this->buildKey($eventType, $criteria)] = true;
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, string> $criteria
     *
     * Build the opaque lock key for one query branch.
     */
    public function buildKey(?string $eventType, array $criteria): string
    {
        ksort($criteria);

        return json_encode([$eventType, $criteria], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $domainIds
     *
     * @return list<array<string, string>>
     */
    private function domainIdentifierSubsets(array $domainIds): array
    {
        ksort($domainIds);

        $subsets = [[]];
        foreach ($domainIds as $dimension => $value) {
            $existingSubsets = $subsets;
            foreach ($existingSubsets as $subset) {
                $subset[$dimension] = $value;
                $subsets[] = $subset;
            }
        }

        array_shift($subsets);

        return $subsets;
    }
}
