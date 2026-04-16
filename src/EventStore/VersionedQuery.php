<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Previously loaded query plus an opaque optimistic-concurrency token.
 *
 * The token is query-scoped: it stores the original `StreamQuery` plus an opaque implementation-owned
 * snapshot token.
 */
readonly class VersionedQuery
{
    public function __construct(
        public VersionToken $token,
        public StreamQuery $query,
    ) {}
}
