<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Doctrine\DBAL\Connection;

final readonly class PostgresGuardLockAdapter implements GuardLockAdapter
{
    public function __construct(private Connection $connection) {}

    public function acquire(string $namespace, string $key, GuardLockMode $mode): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('State store guards require an active transaction.');
        }

        $function = GuardLockMode::SHARED === $mode ? 'pg_advisory_xact_lock_shared' : 'pg_advisory_xact_lock';
        $this->connection->executeQuery(
            'SELECT '.$function.'(hashtext(?), hashtext(?))',
            [$namespace, $key],
        );
    }
}
