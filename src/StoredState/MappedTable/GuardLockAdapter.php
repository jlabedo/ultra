<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface GuardLockAdapter
{
    /** Returns the connection or in-memory database holding the active transaction. */
    public function activeTransactionResource(): object;

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void;
}
