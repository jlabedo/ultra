<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\UnsupportedQueryException;

/**
 * Validates typed stream queries and turns them into canonical query-stream keys.
 *
 * Query validation is deliberately strict: each branch must be typed, may contain at most two identifiers,
 * and pairs must already be canonical for every requested event type. The same factory is also used when
 * indexing appended events so in-memory and persisted stores agree on the exact key set.
 */
final readonly class CanonicalQueryKeyFactory
{
    public function __construct(
        private EventTopologyProvider $topologyProvider,
    ) {}

    /**
     * Resolve a user-facing `StreamQuery` into a validated canonical form.
     *
     * @throws UnsupportedQueryException when a branch is untyped, too wide, unsupported, or non-canonical
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
                throw new UnsupportedQueryException('Canonical query branches may contain at most two domain identifiers.');
            }

            $keys = [];
            foreach ($eventTypes as $eventType) {
                $topology = $this->topologyProvider->get($eventType);
                if (null === $topology) {
                    throw new UnsupportedQueryException(sprintf('No event topology is registered for event type "%s".', $eventType));
                }

                $dimensions = array_keys($criteria);
                if (! $topology->supportsAll($dimensions)) {
                    throw new UnsupportedQueryException(sprintf(
                        'Query branch [%s] is not supported for event type "%s".',
                        implode(', ', $dimensions),
                        $eventType,
                    ));
                }

                if (2 === count($dimensions)) {
                    $canonical = $topology->canonicalize($dimensions);
                    $sorted = $dimensions;
                    sort($sorted);
                    if ($canonical !== $sorted) {
                        throw new UnsupportedQueryException(sprintf(
                            'Query branch [%s] is not a canonical pair for event type "%s".',
                            implode(', ', $sorted),
                            $eventType,
                        ));
                    }
                }

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
     *
     * @throws UnsupportedQueryException when the event type has no topology or the event carries unsupported identifiers
     */
    public function keysForEvent(string $eventType, array $domainIds): array
    {
        $topology = $this->topologyProvider->get($eventType);
        if (null === $topology) {
            throw new UnsupportedQueryException(sprintf('No event topology is registered for event type "%s".', $eventType));
        }

        $dimensions = array_keys($domainIds);
        if (! $topology->supportsAll($dimensions)) {
            throw new UnsupportedQueryException(sprintf(
                'Event type "%s" contains unsupported identifiers [%s] for its topology.',
                $eventType,
                implode(', ', $dimensions),
            ));
        }

        sort($dimensions);
        $keys = [];
        foreach ($dimensions as $dimension) {
            $keys[$this->buildKey($eventType, [$dimension => $domainIds[$dimension]])] = true;
        }

        $count = count($dimensions);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $pair = [$dimensions[$i], $dimensions[$j]];
                if (2 !== count($topology->canonicalize($pair))) {
                    continue;
                }

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
     * Build the opaque storage key for a typed canonical query stream
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
