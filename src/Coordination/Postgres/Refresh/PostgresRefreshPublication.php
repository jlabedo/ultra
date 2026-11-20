<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Refresh;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\TransactionIsolationLevel;
use Ultra\Coordination\Refresh\RefreshPublication;

final readonly class PostgresRefreshPublication implements RefreshPublication
{
    public function __construct(
        private Connection $connection,
        private string $table = 'coordination_refresh_revisions',
    ) {}

    public function revision(string $channel): int
    {
        $this->assertOutsideTransaction();

        return (int) $this->connection->fetchOne(
            sprintf('SELECT revision FROM %s WHERE channel = ?', $this->table),
            [$channel],
            [ParameterType::STRING],
        );
    }

    public function publish(string $channel, int $revision, \Closure $write): bool
    {
        $this->assertOutsideTransaction();
        if (TransactionIsolationLevel::READ_COMMITTED !== $this->connection->getTransactionIsolation()) {
            throw new \LogicException('Refresh publication requires READ COMMITTED isolation.');
        }

        return $this->connection->transactional(function () use ($channel, $revision, $write): bool {
            $advanced = 0 === $revision
                ? $this->connection->executeStatement(
                    sprintf(
                        'INSERT INTO %s (channel, revision) VALUES (?, 1) ON CONFLICT (channel) DO NOTHING',
                        $this->table,
                    ),
                    [$channel],
                    [ParameterType::STRING],
                )
                : $this->connection->executeStatement(
                    sprintf('UPDATE %s SET revision = revision + 1 WHERE channel = ? AND revision = ?', $this->table),
                    [$channel, $revision],
                    [ParameterType::STRING, ParameterType::INTEGER],
                );
            if (0 === (int) $advanced) {
                return false;
            }

            $write();

            return true;
        });
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Refresh publication owns its transaction.');
        }
    }
}
