<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class InMemoryGuardLockAdapter implements GuardLockAdapter
{
    public function __construct(
        private InMemoryRowDatabase $database,
    ) {}

    public function activeTransactionResource(): object
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Query guards require an active transaction.');
        }

        return $this->database;
    }

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        $this->activeTransactionResource();
    }
}
