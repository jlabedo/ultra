<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\EventStore\Checkpoint;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Time\ClockInterface;
use Ultra\Time\Duration;
use Ultra\Time\SystemClock;

final readonly class PostgresProjectionSegmentRepository implements ProjectionSegmentRepositoryInterface
{
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
            sprintf(
                "INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                 VALUES (?, 0, 255, ?, CAST(? AS TIMESTAMPTZ))
                 ON CONFLICT (projection_id, bucket_from) DO NOTHING",
                $this->segmentsTable(),
            ),
            [$projectionId, Checkpoint::beginning()->serialize(), $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return $affected > 0;
    }

    public function claimSegment(
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): ?ProjectionSegment {
        $now = $this->clock->now();

        $this->connection->beginTransaction();

        try {
            $row = $this->connection->executeQuery(
                sprintf(
                    'SELECT bucket_from, bucket_to, checkpoint
                     FROM %s
                     WHERE projection_id = ?
                       AND bucket_from = ?
                       AND (worker_id IS NULL OR worker_id = ?)
                       AND (lease_expires_at IS NULL OR lease_expires_at >= CAST(? AS TIMESTAMPTZ) OR worker_id = ?)
                     FOR UPDATE',
                    $this->segmentsTable(),
                ),
                [$projectionId, $bucketFrom, $workerId, $now, $workerId],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            )->fetchAssociative();

            if (false === $row) {
                $this->connection->rollBack();

                return null;
            }

            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET worker_id = ?,
                         lease_expires_at = CAST(? AS TIMESTAMPTZ),
                         updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from = ?",
                    $this->segmentsTable(),
                ),
                [$workerId, $now->add($leaseDuration), $now, $projectionId, $bucketFrom],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );

            $this->connection->commit();

            return new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
                workerId: $workerId,
            );
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    public function completeClaimedSegment(
        ProjectionSegment $segment,
        Checkpoint $checkpoint,
        string $workerId,
        bool $caughtUp,
        Duration $leaseDuration = new Duration(seconds: 60),
    ): void {
        $now = $this->clock->now();

        if ($caughtUp) {
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET worker_id = NULL, lease_expires_at = NULL,
                         checkpoint = ?, updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from = ? AND worker_id = ?",
                    $this->segmentsTable(),
                ),
                [$checkpoint->serialize(), $now, $segment->projectionId, $segment->bucketFrom, $workerId],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
            );

            return;
        }

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET checkpoint = ?,
                     lease_expires_at = CAST(? AS TIMESTAMPTZ),
                     updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from = ? AND worker_id = ?",
                $this->segmentsTable(),
            ),
            [$checkpoint->serialize(), $now->add($leaseDuration), $now, $segment->projectionId, $segment->bucketFrom, $workerId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function releaseClaimedSegment(ProjectionSegment $segment, string $workerId): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from = ? AND worker_id = ?",
                $this->segmentsTable(),
            ),
            [$now, $segment->projectionId, $segment->bucketFrom, $workerId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    public function recoverExpiredClaims(string $projectionId): int
    {
        $now = $this->clock->now();

        $count = $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND worker_id IS NOT NULL AND lease_expires_at < CAST(? AS TIMESTAMPTZ)",
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
        $this->connection->beginTransaction();

        try {
            $row = $this->connection->executeQuery(
                sprintf(
                    'SELECT bucket_from, bucket_to, checkpoint, worker_id
                     FROM %s WHERE projection_id = ? AND bucket_from = ?
                     FOR UPDATE',
                    $this->segmentsTable(),
                ),
                [$projectionId, $bucketFrom],
                [ParameterType::STRING, ParameterType::INTEGER],
            )->fetchAssociative();

            if (false === $row) {
                $this->connection->commit();

                return;
            }

            if (null !== $row['worker_id']) {
                $this->connection->commit();

                return;
            }

            $rangeSize = (int) $row['bucket_to'] - (int) $row['bucket_from'] + 1;
            if ($rangeSize < 2) {
                throw new \LogicException(sprintf(
                    'Segment [%d-%d] is at minimum granularity and cannot be split.',
                    (int) $row['bucket_from'],
                    (int) $row['bucket_to'],
                ));
            }

            $mid = (int) $row['bucket_from'] + intdiv($rangeSize, 2);
            $serializedCheckpoint = (string) $row['checkpoint'];
            $now = $this->clock->now();

            $this->connection->executeStatement(
                sprintf('DELETE FROM %s WHERE projection_id = ? AND bucket_from = ?', $this->segmentsTable()),
                [$projectionId, $bucketFrom],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

            $this->connection->executeStatement(
                sprintf(
                    "INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                     VALUES (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ)), (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ))",
                    $this->segmentsTable(),
                ),
                [
                    $projectionId,
                    (int) $row['bucket_from'],
                    $mid - 1,
                    $serializedCheckpoint,
                    $now,
                    $projectionId,
                    $mid,
                    (int) $row['bucket_to'],
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
                    "INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                     VALUES (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ)), (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ))",
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

    private function segmentsForRange(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT bucket_from, bucket_to, checkpoint
                 FROM %s
                 WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?
                 ORDER BY bucket_from ASC',
                $this->segmentsTable(),
            ),
            [$projectionId, $bucketFrom, $bucketTo],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): ProjectionSegment => new ProjectionSegment(
            projectionId: $projectionId,
            bucketFrom: (int) $row['bucket_from'],
            bucketTo: (int) $row['bucket_to'],
            checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
        ), $rows);
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
            $rows = $this->connection->executeQuery(
                sprintf(
                    'SELECT bucket_from, bucket_to, checkpoint
                     FROM %s
                     WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?
                     ORDER BY bucket_from ASC
                     FOR UPDATE',
                    $this->segmentsTable(),
                ),
                [$projectionId, $bucketFrom, $bucketTo],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            )->fetchAllAssociative();

            $segments = array_map(static fn (array $row): ProjectionSegment => new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            ), $rows);
            $this->assertSegmentsExactlyCoverRange($projectionId, $segments, $bucketFrom, $bucketTo);

            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET worker_id = ?,
                         lease_expires_at = CAST(? AS TIMESTAMPTZ),
                         updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from >= ? AND bucket_to <= ?",
                    $this->segmentsTable(),
                ),
                [$ownerId, $now->add($leaseDuration), $now, $projectionId, $bucketFrom, $bucketTo],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );

            $this->connection->commit();

            return $segments;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    public function replaceSegmentsWithMergedSegment(string $projectionId, int $bucketFrom, int $bucketTo, Checkpoint $checkpoint): void
    {
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
                sprintf(
                    "INSERT INTO %s (projection_id, bucket_from, bucket_to, checkpoint, updated_at)
                     VALUES (?, ?, ?, ?, CAST(? AS TIMESTAMPTZ))",
                    $this->segmentsTable(),
                ),
                [$projectionId, $bucketFrom, $bucketTo, $checkpoint->serialize(), $now],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
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
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT bucket_from, bucket_to, checkpoint, worker_id
                 FROM %s WHERE projection_id = ? ORDER BY bucket_from ASC',
                $this->segmentsTable(),
            ),
            [$projectionId],
            [ParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): ProjectionSegment => new ProjectionSegment(
            projectionId: $projectionId,
            bucketFrom: (int) $row['bucket_from'],
            bucketTo: (int) $row['bucket_to'],
            checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            workerId: $row['worker_id'] ?? null,
        ), $rows);
    }

    /** @return list<ProjectionSegment> */
    public function availableSegments(string $projectionId): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT bucket_from, bucket_to, checkpoint
                 FROM %s
                 WHERE projection_id = ?
                   AND worker_id IS NULL
                 ORDER BY bucket_from ASC',
                $this->segmentsTable(),
            ),
            [$projectionId],
            [ParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): ProjectionSegment => new ProjectionSegment(
            projectionId: $projectionId,
            bucketFrom: (int) $row['bucket_from'],
            bucketTo: (int) $row['bucket_to'],
            checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
        ), $rows);
    }

    /**
     * @param list<ProjectionSegment> $segments
     */
    private function assertSegmentsExactlyCoverRange(string $projectionId, array $segments, int $bucketFrom, int $bucketTo): void
    {
        usort($segments, static fn (ProjectionSegment $left, ProjectionSegment $right): int => $left->bucketFrom <=> $right->bucketFrom);

        $next = $bucketFrom;
        foreach ($segments as $segment) {
            if ($segment->bucketFrom !== $next) {
                throw new \LogicException(sprintf('Cannot merge [%d-%d] for projection "%s": segments do not cover the range.', $bucketFrom, $bucketTo, $projectionId));
            }

            $next = $segment->bucketTo + 1;
        }

        if ($next !== $bucketTo + 1) {
            throw new \LogicException(sprintf('Cannot merge [%d-%d] for projection "%s": segments do not cover the range.', $bucketFrom, $bucketTo, $projectionId));
        }
    }

    private function segmentsTable(): string
    {
        return $this->tablePrefix.'segments';
    }
}
