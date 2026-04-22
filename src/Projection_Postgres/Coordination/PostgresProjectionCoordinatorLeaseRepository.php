<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Coordination;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;

final readonly class PostgresProjectionCoordinatorLeaseRepository implements ProjectionCoordinatorLeaseRepositoryInterface
{
    private const string COORDINATOR_KEY = 'global';

    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'projection_',
    ) {}

    public function acquire(string $workerId, int $leaseDurationSeconds = 60): bool
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->lockRow();
            if (null !== $row && null !== $row['worker_id'] && null !== $row['lease_expires_at']) {
                $leaseExpiresAt = new \DateTimeImmutable((string) $row['lease_expires_at']);
                if ($leaseExpiresAt >= new \DateTimeImmutable()) {
                    $this->connection->rollBack();
                    return false;
                }
            }

            $this->upsertRow();
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET worker_id = ?, lease_expires_at = now() + interval '%d seconds',
                         wake_enqueued_until = NULL, updated_at = statement_timestamp()
                     WHERE coordinator_key = ?",
                    $this->coordinatorsTable(),
                    $leaseDurationSeconds,
                ),
                [$workerId, self::COORDINATOR_KEY],
                [ParameterType::STRING, ParameterType::STRING],
            );

            $this->connection->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    public function renew(string $workerId, int $leaseDurationSeconds = 60): void
    {
        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET lease_expires_at = now() + interval '%d seconds', updated_at = statement_timestamp()
                 WHERE coordinator_key = ? AND worker_id = ?",
                $this->coordinatorsTable(),
                $leaseDurationSeconds,
            ),
            [self::COORDINATOR_KEY, $workerId],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function release(string $workerId): void
    {
        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET worker_id = NULL, lease_expires_at = NULL, updated_at = statement_timestamp()
                 WHERE coordinator_key = ? AND worker_id = ?",
                $this->coordinatorsTable(),
            ),
            [self::COORDINATOR_KEY, $workerId],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function hasLiveCoordinator(): bool
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT worker_id, lease_expires_at FROM %s WHERE coordinator_key = ?',
                $this->coordinatorsTable(),
            ),
            [self::COORDINATOR_KEY],
            [ParameterType::STRING],
        )->fetchAssociative();

        if (false === $row || null === $row['worker_id'] || null === $row['lease_expires_at']) {
            return false;
        }

        return new \DateTimeImmutable((string) $row['lease_expires_at']) >= new \DateTimeImmutable();
    }

    public function markWakeEnqueued(int $ttlSeconds = 30): bool
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->lockRow();
            $this->upsertRow();
            $now = new \DateTimeImmutable();

            if (null !== $row && null !== $row['worker_id'] && null !== $row['lease_expires_at']) {
                $leaseExpiresAt = new \DateTimeImmutable((string) $row['lease_expires_at']);
                if ($leaseExpiresAt >= $now) {
                    $this->connection->commit();
                    return false;
                }
            }

            if (null !== $row && null !== $row['wake_enqueued_until']) {
                $wakeEnqueuedUntil = new \DateTimeImmutable((string) $row['wake_enqueued_until']);
                if ($wakeEnqueuedUntil >= $now) {
                    $this->connection->commit();
                    return false;
                }
            }

            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET wake_enqueued_until = now() + interval '%d seconds', updated_at = statement_timestamp()
                     WHERE coordinator_key = ?",
                    $this->coordinatorsTable(),
                    $ttlSeconds,
                ),
                [self::COORDINATOR_KEY],
                [ParameterType::STRING],
            );

            $this->connection->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    /** @return array{worker_id: string|null, lease_expires_at: string|null, wake_enqueued_until: string|null}|null */
    private function lockRow(): ?array
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT worker_id, lease_expires_at, wake_enqueued_until FROM %s WHERE coordinator_key = ? FOR UPDATE',
                $this->coordinatorsTable(),
            ),
            [self::COORDINATOR_KEY],
            [ParameterType::STRING],
        )->fetchAssociative();

        return false === $row ? null : $row;
    }

    private function upsertRow(): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (coordinator_key) VALUES (?) ON CONFLICT (coordinator_key) DO NOTHING',
                $this->coordinatorsTable(),
            ),
            [self::COORDINATOR_KEY],
            [ParameterType::STRING],
        );
    }

    private function coordinatorsTable(): string
    {
        return $this->tablePrefix . 'coordinators';
    }
}
