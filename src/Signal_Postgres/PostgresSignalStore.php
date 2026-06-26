<?php

declare(strict_types=1);

namespace Ultra\Signal_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Signal\BucketRange;
use Ultra\Signal\SignalHint;
use Ultra\Signal\SignalStore;
use Ultra\Signal\SuppressionHandle;
use Ultra\Signal\VisibilitySnapshot;
use Ultra\Time\ClockInterface;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final readonly class PostgresSignalStore implements SignalStore
{
    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'ultra_',
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function signal(
        string $queueName,
        int $bucket = 0,
        ?string $signalKey = null,
        ?DatePoint $visibleAt = null,
    ): void {
        $this->assertQueueName($queueName);
        $this->assertSignalKey($signalKey);

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (queue_name, bucket, signal_key, visible_at)
                 VALUES (?, ?, ?, CAST(? AS TIMESTAMPTZ))',
                $this->signalsTable(),
            ),
            [$queueName, $bucket, $signalKey, $visibleAt ?? $this->clock->now()],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function claimHints(
        array $queueNames,
        string $owner,
        int $limit,
        Duration $deliveryTimeout,
    ): array {
        if ([] === $queueNames) {
            return [];
        }

        foreach ($queueNames as $queueName) {
            $this->assertQueueName($queueName);
        }

        if ('' === $owner) {
            throw new \InvalidArgumentException('Signal delivery owner must be non-empty.');
        }

        if ($limit <= 0) {
            throw new \InvalidArgumentException('Signal claim limit must be positive.');
        }

        if ($deliveryTimeout->inMicroseconds() <= 0) {
            throw new \InvalidArgumentException('Signal delivery timeout must be positive.');
        }

        $now = $this->clock->now();
        $queuePlaceholders = implode(', ', array_fill(0, count($queueNames), '?'));
        $params = [
            ...$queueNames,
            $now,
            $now,
            $now,
            $limit,
            $owner,
            $now,
            $deliveryTimeout->inMicroseconds(),
        ];
        $types = [
            ...array_fill(0, count($queueNames), ParameterType::STRING),
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
        ];

        $rows = $this->connection->executeQuery(
            sprintf(
                'WITH candidates AS (
                    SELECT s.id
                    FROM %1$s s
                    WHERE s.queue_name IN (%3$s)
                      AND s.visible_at <= CAST(? AS TIMESTAMPTZ)
                      AND (s.delivery_until IS NULL OR s.delivery_until <= CAST(? AS TIMESTAMPTZ))
                      AND NOT EXISTS (
                          SELECT 1
                          FROM %2$s sup
                          WHERE sup.queue_name = s.queue_name
                            AND sup.suppress_until > CAST(? AS TIMESTAMPTZ)
                            AND sup.bucket_range @> s.bucket
                      )
                    ORDER BY s.id
                    FOR UPDATE SKIP LOCKED
                    LIMIT ?
                )
                UPDATE %1$s s
                SET delivery_owner = ?,
                    delivery_until = CAST(? AS TIMESTAMPTZ) + (? * INTERVAL \'1 microsecond\')
                FROM candidates c
                WHERE s.id = c.id
                RETURNING s.id, s.queue_name, s.bucket, s.signal_key',
                $this->signalsTable(),
                $this->suppressionsTable(),
                $queuePlaceholders,
            ),
            $params,
            $types,
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): SignalHint => new SignalHint(
                (int) $row['id'],
                (string) $row['queue_name'],
                (int) $row['bucket'],
                null === $row['signal_key'] ? null : (string) $row['signal_key'],
            ),
            $rows,
        );
    }

    public function captureVisibilitySnapshot(): VisibilitySnapshot
    {
        $row = $this->connection->executeQuery(
            'SELECT pg_current_snapshot()::text AS snapshot, now() AS visible_at',
        )->fetchAssociative();

        if (false === $row) {
            throw new \RuntimeException('Unable to capture signal visibility snapshot.');
        }

        return new VisibilitySnapshot(
            (string) $row['snapshot'],
            new DatePoint((string) $row['visible_at']),
        );
    }

    public function ackVisibleInSnapshot(
        string $queueName,
        BucketRange $bucketRange,
        VisibilitySnapshot $snapshot,
        ?int $limit = null,
    ): int {
        $this->assertQueueName($queueName);

        if (null !== $limit && $limit <= 0) {
            throw new \InvalidArgumentException('Signal ack limit must be null or positive.');
        }

        return (int) $this->connection->executeStatement(
            sprintf(
                'WITH doomed AS (
                    SELECT s.id
                    FROM %s s
                    WHERE s.queue_name = ?
                      AND s.bucket >= ?
                      AND s.bucket < ?
                      AND s.visible_at <= CAST(? AS TIMESTAMPTZ)
                      AND pg_visible_in_snapshot(s.insert_xid, CAST(? AS pg_snapshot))
                    ORDER BY s.id
                    LIMIT ?
                )
                DELETE FROM %s s
                USING doomed d
                WHERE s.id = d.id',
                $this->signalsTable(),
                $this->signalsTable(),
            ),
            [
                $queueName,
                $bucketRange->lowerInclusive,
                $bucketRange->upperExclusive,
                $snapshot->visibleAt,
                $snapshot->snapshot,
                $limit ?? 2_147_483_647,
            ],
            [
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
            ],
        );
    }

    public function suppressDispatch(
        string $queueName,
        BucketRange $bucketRange,
        string $owner,
        DatePoint $suppressUntil,
        ?string $domainLeaseRef = null,
    ): SuppressionHandle {
        $this->assertQueueName($queueName);

        if ('' === $owner) {
            throw new \InvalidArgumentException('Signal suppression owner must be non-empty.');
        }

        if ('' === $domainLeaseRef) {
            throw new \InvalidArgumentException('Signal suppression domain lease reference must be null or a non-empty string.');
        }

        $row = $this->connection->executeQuery(
            sprintf(
                'INSERT INTO %s (queue_name, bucket_range, owner, domain_lease_ref, suppress_until)
                 VALUES (?, int4range(?, ?, \'[)\'), ?, ?, CAST(? AS TIMESTAMPTZ))
                 RETURNING id, suppress_until',
                $this->suppressionsTable(),
            ),
            [
                $queueName,
                $bucketRange->lowerInclusive,
                $bucketRange->upperExclusive,
                $owner,
                $domainLeaseRef,
                $suppressUntil,
            ],
            [
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            throw new \RuntimeException('Unable to create signal dispatch suppression.');
        }

        return new SuppressionHandle(
            (int) $row['id'],
            $queueName,
            $bucketRange,
            $owner,
            new DatePoint((string) $row['suppress_until']),
        );
    }

    public function extendSuppression(SuppressionHandle $handle, DatePoint $suppressUntil): void
    {
        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET suppress_until = CAST(? AS TIMESTAMPTZ)
                 WHERE id = ? AND queue_name = ? AND owner = ?',
                $this->suppressionsTable(),
            ),
            [$suppressUntil, $handle->id, $handle->queueName, $handle->owner],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function releaseSuppression(SuppressionHandle $handle): void
    {
        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE id = ? AND queue_name = ? AND owner = ?',
                $this->suppressionsTable(),
            ),
            [$handle->id, $handle->queueName, $handle->owner],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
    }

    public function cleanupExpired(): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE suppress_until <= CAST(? AS TIMESTAMPTZ)', $this->suppressionsTable()),
            [$now],
            [ParameterType::STRING],
        );

        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET delivery_owner = NULL,
                     delivery_until = NULL
                 WHERE delivery_until <= CAST(? AS TIMESTAMPTZ)
                   AND delivery_owner IS NOT NULL',
                $this->signalsTable(),
            ),
            [$now],
            [ParameterType::STRING],
        );
    }

    private function signalsTable(): string
    {
        return $this->tablePrefix.'signals';
    }

    private function suppressionsTable(): string
    {
        return $this->tablePrefix.'signal_suppressions';
    }

    private function assertQueueName(string $queueName): void
    {
        if ('' === $queueName) {
            throw new \InvalidArgumentException('Signal queue name must be non-empty.');
        }
    }

    private function assertSignalKey(?string $signalKey): void
    {
        if ('' === $signalKey) {
            throw new \InvalidArgumentException('Signal key must be null or a non-empty string.');
        }
    }
}
