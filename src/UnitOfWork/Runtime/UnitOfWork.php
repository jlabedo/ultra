<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

/**
 * Minimal contract for buffering and flushing work inside an external transaction boundary.
 */
interface UnitOfWork
{
    /**
     * Whether flushing would perform any transactional work.
     */
    public function hasPendingChanges(): bool;

    /**
     * Persist every buffered operation and consume it, including checked expectations, after success.
     * Without new changes, another flush must not repeat completed operations.
     *
     * Implementations assume the surrounding transaction, if any, has already been opened by the caller.
     * Flushing does not commit it. On failure, the caller must roll back and clear before reuse.
     */
    public function flush(): void;

    /**
     * Drop buffered operations and in-memory tracking without rolling back executed writes.
     */
    public function clear(): void;
}
