<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\InMemoryRowDatabase;

final readonly class InMemoryGuardLockAdapter implements GuardLockAdapter
{
    public function __construct(private InMemoryRowDatabase $database) {}

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('State store guards require an active transaction.');
        }
        // A single synchronous transaction has no competing connection to serialize.
    }
}
