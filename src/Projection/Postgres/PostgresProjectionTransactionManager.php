<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\ProjectionTransactionManagerInterface;

final readonly class PostgresProjectionTransactionManager implements ProjectionTransactionManagerInterface
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $operation();

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
