<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Coordination\Tracking\DirtyKeyTracker;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTracker;
use Ultra\EventStore\Checkpoint;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Time\ClockInterface;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final readonly class PostgresProjectionSegmentRepository implements ProjectionSegmentRepositoryInterface
{
    // A claim belongs to the row version written by its UPDATE, even within a caller transaction.
    private const string CLAIM_VERSION = "(xmin::text || ':' || ctid::text)";

    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'projection_',
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * Creates the initial segment [0-255] for a projection if none exist yet.
     * Returns true if the row was actually inserted (first-ever run for this projection).
     */
    public function ensureInitialized(string $projectionId): bool
    {
        $now = $this->clock->now();

        $affected = $this->connection->executeStatement(
            sprintf('INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                 VALUES (?, 0, 255, ?, CAST(? AS TIMESTAMPTZ))
                 ON CONFLICT (projection_id, bucket_from) DO NOTHING', $this->segmentsTable()),
            [$projectionId, Checkpoint::beginning()->serialize(), $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return $affected > 0;
    }

    public function deleteProjection(string $projectionId): void
    {
        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE projection_id = ?', $this->segmentsTable()),
            [$projectionId],
            [ParameterType::STRING],
        );
    }

    public function claimSegment(
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
        bool $requireOwned = false,
    ): ?ProjectionSegment {
        $now = $this->clock->now();
        $ownershipPredicate = $requireOwned ? 'worker_id = ?' : '(worker_id IS NULL OR worker_id = ?)';

        $row = $this->connection
            ->executeQuery(
                sprintf(
                    'UPDATE %s
                 SET worker_id = ?,
                     lease_expires_at = CAST(? AS TIMESTAMPTZ),
                     updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ?
                   AND bucket_from = ?
                   AND %s
                   AND (lease_expires_at IS NULL OR lease_expires_at >= CAST(? AS TIMESTAMPTZ) OR worker_id = ?)
                 RETURNING bucket_from, bucket_to, checkpoint, %s AS claim_version',
                    $this->segmentsTable(),
                    $ownershipPredicate,
                    self::CLAIM_VERSION,
                ),
                [$workerId, $now->add($leaseDuration), $now, $projectionId, $bucketFrom, $workerId, $now, $workerId],
                [
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ],
            )
            ->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return new ProjectionSegment(
            projectionId: $projectionId,
            bucketFrom: (int) $row['bucket_from'],
            bucketTo: (int) $row['bucket_to'],
            checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            workerId: $workerId,
            claimVersion: (string) $row['claim_version'],
        );
    }

    public function claimFullRangeSegment(
        string $projectionId,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): ?ProjectionSegment {
        $now = $this->clock->now();
        $row = $this->connection->fetchAssociative(
            sprintf(
                'UPDATE %s
                 SET worker_id = ?, lease_expires_at = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from = 0 AND bucket_to = 255
                   AND (worker_id IS NULL OR lease_expires_at < CAST(? AS TIMESTAMPTZ))
                 RETURNING checkpoint, %s AS claim_version',
                $this->segmentsTable(),
                self::CLAIM_VERSION,
            ),
            [$workerId, $now->add($leaseDuration), $now, $projectionId, $now],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );

        return false === $row
            ? null
            : new ProjectionSegment(
                $projectionId,
                0,
                255,
                Checkpoint::deserialize((string) $row['checkpoint']),
                $workerId,
                (string) $row['claim_version'],
            );
    }

    /** @param array<int, int> $seenCounters */
    public function completeClaimedSegment(
        ProjectionSegment $segment,
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        Duration $leaseDuration = new Duration(seconds: 60),
        ?DirtyKeyTracker $activity = null,
        array $seenCounters = [],
    ): ?int {
        $now = $this->clock->now();
        $sql = sprintf(
            'UPDATE %s
             SET checkpoint = ?, worker_id = ?, lease_expires_at = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
             WHERE projection_id = ? AND bucket_from = ? AND bucket_to = ? AND worker_id = ?',
            $this->segmentsTable(),
        );
        $params = [
            $checkpoint->serialize(),
            $caughtUp ? null : $workerId,
            $caughtUp ? null : $now->add($leaseDuration),
            $now,
            $segment->projectionId,
            $segment->bucketFrom,
            $segment->bucketTo,
            $workerId,
        ];
        $types = [
            ParameterType::STRING,
            $caughtUp ? ParameterType::NULL : ParameterType::STRING,
            $caughtUp ? ParameterType::NULL : ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
            ParameterType::STRING,
        ];
        if (null !== $segment->claimVersion) {
            $sql .= ' AND ' . self::CLAIM_VERSION . ' = ? AND lease_expires_at >= CAST(? AS TIMESTAMPTZ)';
            array_push($params, $segment->claimVersion, $now);
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }

        if ($caughtUp && $activity instanceof PostgresDirtyKeyTracker && $activity->usesConnection($this->connection)) {
            $drain = $activity->drainSeenStatement($segment->projectionId, $seenCounters);
            if (null !== $drain) {
                [$drainSql, $drainParams, $drainTypes] = $drain;
                $count = $this->connection->fetchOne(
                    sprintf(
                        'WITH completed AS (%s RETURNING 1),
                         drained AS (%s AND EXISTS (SELECT 1 FROM completed) RETURNING 1)
                         SELECT (SELECT count(*) FROM drained) FROM completed',
                        $sql,
                        $drainSql,
                    ),
                    [...$params, ...$drainParams],
                    [...$types, ...$drainTypes],
                );

                return false === $count ? null : (int) $count;
            }
        }

        if (0 === (int) $this->connection->executeStatement($sql, $params, $types)) {
            return null;
        }

        return $caughtUp && [] !== $seenCounters
            ? $activity?->drainSeen($segment->projectionId, $seenCounters) ?? 0
            : 0;
    }

    public function releaseClaimedSegment(ProjectionSegment $segment, string $workerId): void
    {
        $now = $this->clock->now();
        $sql = sprintf(
            'UPDATE %s
             SET worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
             WHERE projection_id = ? AND bucket_from = ? AND bucket_to = ? AND worker_id = ?',
            $this->segmentsTable(),
        );
        $params = [$now, $segment->projectionId, $segment->bucketFrom, $segment->bucketTo, $workerId];
        $types = [
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
            ParameterType::STRING,
        ];
        if (null !== $segment->claimVersion) {
            $sql .= ' AND ' . self::CLAIM_VERSION . ' = ?';
            $params[] = $segment->claimVersion;
            $types[] = ParameterType::STRING;
        }

        $this->connection->executeStatement($sql, $params, $types);
    }

    public function recoverExpiredClaims(string $projectionId): int
    {
        $now = $this->clock->now();

        $count = $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND worker_id IS NOT NULL AND lease_expires_at < CAST(? AS TIMESTAMPTZ)',
                $this->segmentsTable(),
            ),
            [$now, $projectionId, $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return (int) $count;
    }

    /**
     * Splits the given segment immediately.
     */
    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $row = $this->connection
            ->executeQuery(
                sprintf('SELECT bucket_from, bucket_to, checkpoint
                 FROM %s WHERE projection_id = ? AND bucket_from = ?', $this->segmentsTable()),
                [$projectionId, $bucketFrom],
                [ParameterType::STRING, ParameterType::INTEGER],
            )
            ->fetchAssociative();

        if (false === $row) {
            throw new \InvalidArgumentException(sprintf(
                'Segment [%d] not found for projection "%s".',
                $bucketFrom,
                $projectionId,
            ));
        }

        $this->finalizeSplit(
            new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            ),
            Checkpoint::deserialize((string) $row['checkpoint']),
        );
    }

    public function takeSegmentsForRange(
        string $projectionId,
        int $bucketFrom,
        int $bucketTo,
        string $ownerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): array {
        $now = $this->clock->now();
        $this->connection->beginTransaction();

        try {
            $rows = $this->connection
                ->executeQuery(
                    sprintf('SELECT bucket_from, bucket_to, checkpoint
                     FROM %s
                     WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?
                     ORDER BY bucket_from ASC
                     FOR UPDATE', $this->segmentsTable()),
                    [$projectionId, $bucketFrom, $bucketTo],
                    [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
                )
                ->fetchAllAssociative();

            $segments = array_map(
                static fn(array $row): ProjectionSegment => new ProjectionSegment(
                    projectionId: $projectionId,
                    bucketFrom: (int) $row['bucket_from'],
                    bucketTo: (int) $row['bucket_to'],
                    checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
                ),
                $rows,
            );
            $this->assertSegmentsExactlyCoverRange($projectionId, $segments, $bucketFrom, $bucketTo);

            $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s
                     SET worker_id = ?,
                         lease_expires_at = CAST(? AS TIMESTAMPTZ),
                         updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?',
                    $this->segmentsTable(),
                ),
                [$ownerId, $now->add($leaseDuration), $now, $projectionId, $bucketFrom, $bucketTo],
                [
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::INTEGER,
                ],
            );

            $this->connection->commit();

            return $segments;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    public function replaceSegmentsWithMergedSegment(
        string $projectionId,
        int $bucketFrom,
        int $bucketTo,
        Checkpoint $checkpoint,
    ): void {
        $segments = $this->segmentsForRange($projectionId, $bucketFrom, $bucketTo);
        if ([] === $segments) {
            throw new \LogicException(sprintf(
                'Cannot merge [%d-%d] for projection "%s": no matching segments found.',
                $bucketFrom,
                $bucketTo,
                $projectionId,
            ));
        }

        $this->assertSegmentsExactlyCoverRange($projectionId, $segments, $bucketFrom, $bucketTo);

        $now = $this->clock->now();
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?',
                    $this->segmentsTable(),
                ),
                [$projectionId, $bucketFrom, $bucketTo],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );

            $this->connection->executeStatement(
                sprintf('INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                     VALUES (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ))', $this->segmentsTable()),
                [$projectionId, $bucketFrom, $bucketTo, $checkpoint->serialize(), $now],
                [
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /** @return list<ProjectionSegment> */
    public function listSegments(string $projectionId): array
    {
        $rows = $this->connection
            ->executeQuery(
                sprintf(
                    'SELECT bucket_from, bucket_to, checkpoint, worker_id
                 FROM %s WHERE projection_id = ? ORDER BY bucket_from ASC',
                    $this->segmentsTable(),
                ),
                [$projectionId],
                [ParameterType::STRING],
            )
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionSegment => new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
                workerId: null === $row['worker_id'] ? null : (string) $row['worker_id'],
            ),
            $rows,
        );
    }

    /** @return list<ProjectionSegment> */
    public function availableSegments(string $projectionId): array
    {
        $rows = $this->connection
            ->executeQuery(
                sprintf('SELECT bucket_from, bucket_to, checkpoint
                 FROM %s
                 WHERE projection_id = ?
                   AND worker_id IS NULL
                 ORDER BY bucket_from ASC', $this->segmentsTable()),
                [$projectionId],
                [ParameterType::STRING],
            )
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionSegment => new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            ),
            $rows,
        );
    }

    /**
     * Executes the split transaction: creates two child segments, removes the parent.
     */
    private function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        $rangeSize = $segment->bucketTo - $segment->bucketFrom + 1;
        if ($rangeSize < 2) {
            throw new \LogicException(sprintf(
                'Segment [%d-%d] is at minimum granularity and cannot be split.',
                $segment->bucketFrom,
                $segment->bucketTo,
            ));
        }

        $mid = $segment->bucketFrom + intdiv($rangeSize, 2);
        $serializedCheckpoint = $checkpoint->serialize();
        $now = $this->clock->now();

        $this->connection->beginTransaction();

        try {
            // Delete parent first: child-1 reuses the same bucket_from as the parent,
            // so INSERT must not occur while the parent row still holds the PK.
            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE projection_id = ? AND bucket_from = ?', $this->segmentsTable()),
                [$segment->projectionId, $segment->bucketFrom],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                     VALUES (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ)), (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ))',
                    $this->segmentsTable(),
                ),
                [
                    $segment->projectionId,
                    $segment->bucketFrom,
                    $mid - 1,
                    $serializedCheckpoint,
                    $now,
                    $segment->projectionId,
                    $mid,
                    $segment->bucketTo,
                    $serializedCheckpoint,
                    $now,
                ],
                [
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                    ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * @return list<ProjectionSegment>
     */
    private function segmentsForRange(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        $rows = $this->connection
            ->executeQuery(
                sprintf('SELECT bucket_from, bucket_to, checkpoint
                 FROM %s
                 WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?
                 ORDER BY bucket_from ASC', $this->segmentsTable()),
                [$projectionId, $bucketFrom, $bucketTo],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            )
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionSegment => new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            ),
            $rows,
        );
    }

    /**
     * @param list<ProjectionSegment> $segments
     */
    private function assertSegmentsExactlyCoverRange(
        string $projectionId,
        array $segments,
        int $bucketFrom,
        int $bucketTo,
    ): void {
        usort(
            $segments,
            static fn(ProjectionSegment $left, ProjectionSegment $right): int => (
                $left->bucketFrom <=> $right->bucketFrom
            ),
        );

        $next = $bucketFrom;
        foreach ($segments as $segment) {
            if ($segment->bucketFrom !== $next) {
                throw new \LogicException(sprintf(
                    'Cannot merge [%d-%d] for projection "%s": segments do not cover the range.',
                    $bucketFrom,
                    $bucketTo,
                    $projectionId,
                ));
            }

            $next = $segment->bucketTo + 1;
        }

        if ($next !== ($bucketTo + 1)) {
            throw new \LogicException(sprintf(
                'Cannot merge [%d-%d] for projection "%s": segments do not cover the range.',
                $bucketFrom,
                $bucketTo,
                $projectionId,
            ));
        }
    }

    private function segmentsTable(): string
    {
        return $this->tablePrefix . 'segments';
    }
}
