<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

/**
 * Minimal contract for buffering and later committing work inside an external transaction boundary.
 */
interface UnitOfWork
{
    /**
     * Whether flushing would perform any transactional work.
     */
    public function hasPendingChanges(): bool;

    /**
     * Persist every buffered operation.
     *
     * Implementations assume the surrounding transaction, if any, has already been opened by the caller.
     */
    public function flush(): void;

    /**
     * Drop every buffered operation after commit or rollback.
     */
    public function clear(): void;
}
