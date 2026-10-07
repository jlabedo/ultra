<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\TransactionManager;

final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function transactional(callable $callback): mixed
    {
        $ownsTransaction = !$this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $callback();

            if ($ownsTransaction) {
                $this->connection->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
