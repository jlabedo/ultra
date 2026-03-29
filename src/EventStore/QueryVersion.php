<?php

declare(strict_types=1);

namespace Ultra\EventStore;

use Ultra\EventStore\Exception\ConcurrencyException;

/**
 * Opaque optimistic-concurrency token for a previously loaded query.
 *
 * The token is query-scoped: it stores the original `StreamQuery` plus the number of matching events
 * visible in that snapshot. `0` means the query returned no events.
 */
readonly class QueryVersion
{
    public function __construct(
        public int $version,
        public StreamQuery $query,
    ) {}

    public static function empty(StreamQuery $query): self
    {
        return new self(0, $query);
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
        if ($this->version !== $expected->version) {
            throw new ConcurrencyException(
                self::describeQuery($expected->query),
                $expected->version,
                $this->version,
            );
        }
    }

    private static function describeQuery(StreamQuery $query): string
    {
        $description = json_encode([
            'domainIdentifiers' => $query->domainIdentifiers,
            'eventNames' => array_values($query->eventNames),
            'orCriteria' => array_values($query->orCriteria),
            'orBranchEventNames' => null === $query->orBranchEventNames
                ? null
                : array_map(static fn (array $types): array => array_values($types), $query->orBranchEventNames),
        ]);

        return is_string($description) ? $description : 'query';
    }
}
