<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

interface TransactionManager
{
    /**
     * Execute the callback inside a transaction boundary.
     *
     * Implementations are responsible for committing when the callback returns
     * and rolling back when it throws.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
