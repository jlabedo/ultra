<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;

/** Owns the transaction boundary and optionally overrides the connection's isolation for its duration. */
final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(
        private Connection $connection,
        private ?TransactionIsolationLevel $isolationLevel = null,
    ) {}

    public function transactional(callable $callback): mixed
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('The transaction manager must own its database transaction.');
        }

        $previousIsolation = $this->connection->getTransactionIsolation();
        $overrideIsolation = null !== $this->isolationLevel && $this->isolationLevel !== $previousIsolation;
        try {
            if ($overrideIsolation) {
                $this->connection->setTransactionIsolation($this->isolationLevel);
            }

            return $this->connection->transactional(static fn(): mixed => $callback());
        } finally {
            if ($overrideIsolation) {
                $this->connection->setTransactionIsolation($previousIsolation);
            }
        }
    }
}
