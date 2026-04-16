<?php

declare(strict_types=1);

namespace Ultra\EventStore_Postgres;

use Ultra\EventStore\VersionToken;

/**
 * @internal
 */
final readonly class PostgresEventStoreVersionToken implements VersionToken
{
    public function __construct(
        private int $matchingEventCount,
    ) {}

    public function matches(VersionToken $expected): bool
    {
        return $expected instanceof self
            && $this->matchingEventCount === $expected->matchingEventCount;
    }
}
