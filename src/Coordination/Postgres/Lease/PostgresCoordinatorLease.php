<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Lease;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Time\ClockInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final readonly class PostgresCoordinatorLease implements CoordinatorLease
{
    public function __construct(
        private Connection $connection,
        private string $table = 'coordination_leases',
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function acquire(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): bool {
        $this->connection->beginTransaction();

        try {
            $row = $this->lockRow($coordinatorKey);
            $now = $this->clock->now();
            if (null !== $row && null !== $row['worker_id'] && null !== $row['lease_expires_at']) {
                $leaseExpiresAt = new DatePoint($row['lease_expires_at']);
                if ($leaseExpiresAt >= $now) {
                    $this->connection->rollBack();

                    return false;
                }
            }

            $this->connection->executeStatement(
                sprintf('UPDATE %s
                     SET worker_id = ?, lease_expires_at = CAST(? AS TIMESTAMPTZ),
                         wake_enqueued_until = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE coordinator_key = ?', $this->table),
                [$workerId, $now->add($leaseDuration), $now, $coordinatorKey],
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

    public function renew(
        string $coordinatorKey,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf('UPDATE %s
                 SET lease_expires_at = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE coordinator_key = ? AND worker_id = ?', $this->table),
            [$now->add($leaseDuration), $now, $coordinatorKey, $workerId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function release(string $coordinatorKey, string $workerId): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf('UPDATE %s
                 SET worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE coordinator_key = ? AND worker_id = ?', $this->table),
            [$now, $coordinatorKey, $workerId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function hasLiveCoordinator(string $coordinatorKey): bool
    {
        /** @var array{worker_id: ?string, lease_expires_at: ?string}|false $row */
        $row = $this->connection
            ->executeQuery(
                sprintf('SELECT worker_id, lease_expires_at FROM %s WHERE coordinator_key = ?', $this->table),
                [$coordinatorKey],
                [ParameterType::STRING],
            )
            ->fetchAssociative();

        if (false === $row || null === $row['worker_id'] || null === $row['lease_expires_at']) {
            return false;
        }

        return new \DateTimeImmutable($row['lease_expires_at']) >= $this->clock->now();
    }

    public function markWakeEnqueued(string $coordinatorKey, Duration $ttl = new Duration(seconds: 30)): bool
    {
        $now = $this->clock->now();

        return $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s AS lease (coordinator_key, wake_enqueued_until, updated_at)
                 VALUES (?, CAST(? AS TIMESTAMPTZ), CAST(? AS TIMESTAMPTZ))
                 ON CONFLICT (coordinator_key) DO UPDATE
                 SET wake_enqueued_until = EXCLUDED.wake_enqueued_until, updated_at = EXCLUDED.updated_at
                 WHERE (lease.worker_id IS NULL OR lease.lease_expires_at IS NULL
                        OR lease.lease_expires_at < EXCLUDED.updated_at)
                   AND (lease.wake_enqueued_until IS NULL OR lease.wake_enqueued_until < EXCLUDED.updated_at)',
                $this->table,
            ),
            [$coordinatorKey, $now->add($ttl), $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        ) > 0;
    }

    /** @return null|array{worker_id: null|string, lease_expires_at: null|string} */
    private function lockRow(string $coordinatorKey): ?array
    {
        // A competing insert can wait on the unique key. Read the row only
        // after that wait so the decision includes the committed reservation.
        $this->upsertRow($coordinatorKey, $this->clock->now());

        /** @var array{worker_id: ?string, lease_expires_at: ?string}|false $row */
        $row = $this->connection
            ->executeQuery(
                sprintf(
                    'SELECT worker_id, lease_expires_at FROM %s WHERE coordinator_key = ? FOR UPDATE',
                    $this->table,
                ),
                [$coordinatorKey],
                [ParameterType::STRING],
            )
            ->fetchAssociative();

        return false === $row ? null : $row;
    }

    private function upsertRow(string $coordinatorKey, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (coordinator_key, updated_at) VALUES (?, CAST(? AS TIMESTAMPTZ)) ON CONFLICT (coordinator_key) DO NOTHING',
                $this->table,
            ),
            [$coordinatorKey, $now],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }
}
