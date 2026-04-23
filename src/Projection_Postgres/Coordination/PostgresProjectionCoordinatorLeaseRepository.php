<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Coordination;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ultra\Projection\Coordination\ProjectionCoordinatorLeaseRepositoryInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final readonly class PostgresProjectionCoordinatorLeaseRepository implements ProjectionCoordinatorLeaseRepositoryInterface
{
    private const string COORDINATOR_KEY = 'global';

    private ClockInterface $clock;

    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'projection_',
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function acquire(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): bool
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->lockRow();
            $now = $this->now();
            if (null !== $row && null !== $row['worker_id'] && null !== $row['lease_expires_at']) {
                $leaseExpiresAt = new DatePoint((string) $row['lease_expires_at']);
                if ($leaseExpiresAt >= $now) {
                    $this->connection->rollBack();
                    return false;
                }
            }

            $this->upsertRow($now);
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET worker_id = ?, lease_expires_at = CAST(? AS TIMESTAMPTZ),
                         wake_enqueued_until = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE coordinator_key = ?",
                    $this->coordinatorsTable(),
                ),
                [$workerId, $now->add($leaseDuration), $now, self::COORDINATOR_KEY],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
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

    public function renew(string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void
    {
        $now = $this->now();

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET lease_expires_at = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE coordinator_key = ? AND worker_id = ?",
                $this->coordinatorsTable(),
            ),
            [$now->add($leaseDuration), $now, self::COORDINATOR_KEY, $workerId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function release(string $workerId): void
    {
        $now = $this->now();

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE coordinator_key = ? AND worker_id = ?",
                $this->coordinatorsTable(),
            ),
            [$now, self::COORDINATOR_KEY, $workerId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
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

        return new \DateTimeImmutable((string) $row['lease_expires_at']) >= $this->now();
    }

    public function markWakeEnqueued(Duration $ttl = new Duration(seconds: 30)): bool
    {
        $this->connection->beginTransaction();

        try {
            $row = $this->lockRow();
            $now = $this->now();
            $this->upsertRow($now);

            if (null !== $row && null !== $row['worker_id'] && null !== $row['lease_expires_at']) {
                $leaseExpiresAt = new DatePoint((string) $row['lease_expires_at']);
                if ($leaseExpiresAt >= $now) {
                    $this->connection->commit();
                    return false;
                }
            }

            if (null !== $row && null !== $row['wake_enqueued_until']) {
                $wakeEnqueuedUntil = new DatePoint((string) $row['wake_enqueued_until']);
                if ($wakeEnqueuedUntil >= $now) {
                    $this->connection->commit();
                    return false;
                }
            }

            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET wake_enqueued_until = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE coordinator_key = ?",
                    $this->coordinatorsTable(),
                ),
                [$now->add($ttl), $now, self::COORDINATOR_KEY],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
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

    private function upsertRow(\DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (coordinator_key, updated_at) VALUES (?, CAST(? AS TIMESTAMPTZ)) ON CONFLICT (coordinator_key) DO NOTHING',
                $this->coordinatorsTable(),
            ),
            [self::COORDINATOR_KEY, $now],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }

    private function coordinatorsTable(): string
    {
        return $this->tablePrefix . 'coordinators';
    }

    private function now(): DatePoint
    {
        return DatePoint::createFromInterface($this->clock->now());
    }
}
