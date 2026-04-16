<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Optimistic-concurrency token for a previously loaded query.
 *
 * The token is query-scoped: it stores the original `StreamQuery` plus an opaque implementation-owned
 * snapshot token.
 */
readonly class QueryVersion
{
    public function __construct(
        public VersionToken $token,
        public StreamQuery $query,
    ) {}
}
