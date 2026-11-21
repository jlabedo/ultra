<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final readonly class PostgresGuardLockAdapter implements GuardLockAdapter
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function activeTransactionResource(): object
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Query guards require an active transaction.');
        }

        return $this->connection;
    }

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        $this->activeTransactionResource();
        $sql = match ($mode) {
            GuardLockMode::SHARED => 'SELECT pg_advisory_xact_lock_shared(hashtext(?), hashtext(?))',
            GuardLockMode::EXCLUSIVE => 'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
        };
        $this->connection->executeQuery($sql, [$namespace, $key]);
    }
}
