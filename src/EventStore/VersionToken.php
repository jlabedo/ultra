<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * Opaque optimistic-concurrency token owned by an event store implementation.
 *
 * Callers receive tokens from `EventStore::load()` and pass them back to `EventStore::append()`.
 * They must not inspect the concrete token shape.
 */
interface VersionToken
{
    public function matches(VersionToken $expected): bool;
}
