<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Ultra\EventStore\Checkpoint;
use Ultra\Projection\ProjectionSegment;
use Ultra\Projection\ProjectionSegmentRepositoryInterface;
use Ultra\Time\DatePoint;
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
        $now = $this->now();

        $affected = $this->connection->executeStatement(
            sprintf(
                "INSERT INTO %s (projection_id, bucket_from, bucket_to, status, checkpoint, updated_at)
                 VALUES (?, 0, 255, 'available', ?, CAST(? AS TIMESTAMPTZ))
                 ON CONFLICT (projection_id, bucket_from) DO NOTHING",
                $this->segmentsTable(),
            ),
            [$projectionId, Checkpoint::beginning()->serialize(), $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return $affected > 0;
    }

    /**
     * Claims an available segment for a worker. Returns null if no segment is available.
     */
    public function claimSegment(
        string $projectionId,
        string $workerId,
        Duration $leaseDurationSeconds = new Duration(seconds: 60),
    ): ?ProjectionSegment {
        return $this->claimByPredicate(
            $projectionId,
            $workerId,
            $leaseDurationSeconds,
            'AND status IN (\'available\', \'draining\')',
            [],
            [],
        );
    }

    public function claimSegmentForBucket(
        string $projectionId,
        int $bucket,
        string $workerId,
        Duration $leaseDurationSeconds = new Duration(seconds: 60)
    ): ?ProjectionSegment {
        return $this->claimByPredicate(
            $projectionId,
            $workerId,
            $leaseDurationSeconds,
            'AND status IN (\'available\', \'draining\') AND bucket_from <= ? AND bucket_to >= ?',
            [$bucket, $bucket],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    private function claimByPredicate(
        string $projectionId,
        string $workerId,
        Duration $leaseDurationSeconds,
        string $extraWhereSql,
        array $extraParams,
        array $extraTypes,
    ): ?ProjectionSegment {
        $this->connection->beginTransaction();

        try {
            $row = $this->connection->executeQuery(
                sprintf(
                    'SELECT bucket_from, bucket_to, status, pending_operation, checkpoint
                     FROM %s
                     WHERE projection_id = ?
                       %s
                     ORDER BY bucket_from ASC
                     FOR UPDATE SKIP LOCKED
                     LIMIT 1',
                    $this->segmentsTable(),
                    $extraWhereSql,
                ),
                [$projectionId, ...$extraParams],
                [ParameterType::STRING, ...$extraTypes],
            )->fetchAssociative();

            if (false === $row) {
                $this->connection->rollBack();

                return null;
            }

            $now = $this->now();
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET status = CASE WHEN pending_operation IS NULL THEN 'active' ELSE 'draining' END,
                         worker_id = ?, lease_expires_at = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from = ?",
                    $this->segmentsTable(),
                ),
                [
                    $workerId,
                    $now->add($leaseDurationSeconds),
                    $now,
                    $projectionId,
                    $row['bucket_from'],
                ],
                [
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::INTEGER,
                ],
            );

            $this->connection->commit();

            return new ProjectionSegment(
                projectionId: $projectionId,
                bucketFrom: (int) $row['bucket_from'],
                bucketTo: (int) $row['bucket_to'],
                status: 'active',
                pendingOperation: $row['pending_operation'] ?? null,
                checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
                wasCreated: false,
            );
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * Reads the current state of a segment from DB, without locking.
     * Used by the runner to detect mid-batch draining requests.
     */
    public function readCurrentState(string $projectionId, int $bucketFrom): ProjectionSegment
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT bucket_from, bucket_to, status, pending_operation, checkpoint
                 FROM %s WHERE projection_id = ? AND bucket_from = ?',
                $this->segmentsTable(),
            ),
            [$projectionId, $bucketFrom],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchAssociative();

        if (false === $row) {
            throw new \LogicException(sprintf(
                'Segment [%d] not found for projection "%s".',
                $bucketFrom,
                $projectionId,
            ));
        }

        return new ProjectionSegment(
            projectionId: $projectionId,
            bucketFrom: (int) $row['bucket_from'],
            bucketTo: (int) $row['bucket_to'],
            status: $row['status'],
            pendingOperation: $row['pending_operation'] ?? null,
            checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
            wasCreated: false,
        );
    }

    /**
     * Releases a segment back to available status and saves its checkpoint.
     */
    public function completeSegment(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        $now = $this->now();

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET status = CASE WHEN pending_operation IS NULL THEN 'available' ELSE 'drained' END,
                     worker_id = NULL, lease_expires_at = NULL,
                     checkpoint = ?, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from = ?",
                $this->segmentsTable(),
            ),
            [$checkpoint->serialize(), $now, $segment->projectionId, $segment->bucketFrom],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function abandonSegment(ProjectionSegment $segment): void
    {
        $now = $this->clock->now();

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET status = CASE WHEN pending_operation IS NULL THEN 'available' ELSE 'draining' END,
                     worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from = ?",
                $this->segmentsTable(),
            ),
            [$now, $segment->projectionId, $segment->bucketFrom],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    /**
     * Renews the worker's lease on a segment to prevent expiry during long batches.
     */
    public function renewLease(ProjectionSegment $segment, string $workerId, Duration $leaseDuration = new Duration(seconds: 60)): void
    {
        $now = $this->now();

        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET lease_expires_at = CAST(? AS TIMESTAMPTZ), updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from = ? AND worker_id = ? AND status = 'active'",
                $this->segmentsTable(),
            ),
            [
                $now->add($leaseDuration),
                $now,
                $segment->projectionId,
                $segment->bucketFrom,
                $workerId,
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::INTEGER,
                ParameterType::STRING,
            ],
        );
    }

    /**
     * Reclaims segments whose worker lease has expired.
     */
    public function recoverExpiredLeases(string $projectionId): int
    {
        $now = $this->clock->now();

        $count = $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET status = CASE WHEN pending_operation IS NULL THEN 'available' ELSE 'draining' END,
                     worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND status IN ('active', 'draining') AND lease_expires_at < CAST(? AS TIMESTAMPTZ)",
                $this->segmentsTable(),
            ),
            [$now, $projectionId, $now],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return (int) $count;
    }

    /**
     * Requests a split of the given segment.
     * If available: executes immediately. If active: marks draining so the worker finalizes.
     */
    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $row = $this->connection->executeQuery(
            sprintf(
                'SELECT bucket_from, bucket_to, status, pending_operation, checkpoint
                 FROM %s WHERE projection_id = ? AND bucket_from = ?',
                $this->segmentsTable(),
            ),
            [$projectionId, $bucketFrom],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchAssociative();

        if (false === $row) {
            throw new \InvalidArgumentException(sprintf(
                'Segment [%d] not found for projection "%s".',
                $bucketFrom,
                $projectionId,
            ));
        }

        if ('active' === $row['status']) {
            $now = $this->clock->now();
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET status = 'draining', pending_operation = 'split', updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from = ? AND status = 'active'",
                    $this->segmentsTable(),
                ),
                [$now, $projectionId, $bucketFrom],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );

            return;
        }

        if ('available' === $row['status']) {
            $now = $this->clock->now();
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET status = 'drained', pending_operation = 'split', updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from = ?",
                    $this->segmentsTable(),
                ),
                [$now, $projectionId, $bucketFrom],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );

            return;
        }

        if ('drained' !== $row['status']) {
            throw new \LogicException(sprintf(
                'Cannot split segment [%d-%d] of projection "%s": status is "%s".',
                $row['bucket_from'],
                $row['bucket_to'],
                $projectionId,
                $row['status'],
            ));
        }
    }

    /**
     * Executes the split transaction: creates two child segments, removes the parent.
     */
    public function finalizeSplit(ProjectionSegment $segment, Checkpoint $checkpoint): void
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
                    "INSERT INTO %s (projection_id, bucket_from, bucket_to, status, checkpoint, updated_at)
                     VALUES (?, ?, ?, 'available', ?, CAST(? AS TIMESTAMPTZ)), (?, ?, ?, 'available', ?, CAST(? AS TIMESTAMPTZ))",
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
     * Marks two sibling segments as draining for a merge.
     */
    public function requestMerge(string $projectionId, int $bucketFrom, int $bucketTo): void
    {
        $rangeSize = $bucketTo - $bucketFrom + 1;
        $mid = $bucketFrom + intdiv($rangeSize, 2);
        $now = $this->clock->now();

        $updated = $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET status = CASE WHEN status = 'active' THEN 'draining' ELSE 'drained' END,
                     pending_operation = 'merge', updated_at = CAST(? AS TIMESTAMPTZ)
                 WHERE projection_id = ? AND bucket_from IN (?, ?) AND status IN ('available', 'active')",
                $this->segmentsTable(),
            ),
            [$now, $projectionId, $bucketFrom, $mid],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        if (2 !== $updated) {
            throw new \LogicException(sprintf(
                'Cannot merge [%d-%d] for projection "%s": both siblings must be available or active (updated %d).',
                $bucketFrom,
                $bucketTo,
                $projectionId,
                $updated,
            ));
        }
    }

    public function reconcilePendingOperations(string $projectionId): int
    {
        $reconciled = 0;

        do {
            $progressed = false;

            $rows = $this->connection->executeQuery(
                sprintf(
                    'SELECT bucket_from, bucket_to, status, pending_operation, checkpoint
                     FROM %s
                     WHERE projection_id = ? AND pending_operation IS NOT NULL
                     ORDER BY bucket_from ASC',
                    $this->segmentsTable(),
                ),
                [$projectionId],
                [ParameterType::STRING],
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                if (($row['pending_operation'] ?? null) === 'split' && 'drained' === $row['status']) {
                    $this->finalizeSplit(
                        new ProjectionSegment(
                            projectionId: $projectionId,
                            bucketFrom: (int) $row['bucket_from'],
                            bucketTo: (int) $row['bucket_to'],
                            status: $row['status'],
                            pendingOperation: $row['pending_operation'] ?? null,
                            checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
                            wasCreated: false,
                        ),
                        Checkpoint::deserialize((string) $row['checkpoint']),
                    );
                    ++$reconciled;
                    $progressed = true;

                    break;
                }

                if (($row['pending_operation'] ?? null) !== 'merge' || 'drained' !== $row['status']) {
                    continue;
                }

                $before = count($rows);
                $this->finalizeMerge(
                    new ProjectionSegment(
                        projectionId: $projectionId,
                        bucketFrom: (int) $row['bucket_from'],
                        bucketTo: (int) $row['bucket_to'],
                        status: $row['status'],
                        pendingOperation: $row['pending_operation'] ?? null,
                        checkpoint: Checkpoint::deserialize((string) $row['checkpoint']),
                        wasCreated: false,
                    ),
                    Checkpoint::deserialize((string) $row['checkpoint']),
                );

                $after = $this->connection->fetchOne(
                    sprintf('SELECT count(*) FROM %s WHERE projection_id = ?', $this->segmentsTable()),
                    [$projectionId],
                    [ParameterType::STRING],
                );

                if ((int) $after !== $before) {
                    ++$reconciled;
                    $progressed = true;

                    break;
                }
            }
        } while ($progressed);

        return $reconciled;
    }

    /**
     * Called by a worker draining for a merge. The last sibling to drain finalizes the merge.
     */
    public function finalizeMerge(ProjectionSegment $segment, Checkpoint $checkpoint): void
    {
        [$parentFrom, $parentTo, $siblingFrom] = $this->resolveMergeSibling($segment);
        $now = $this->clock->now();

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                sprintf(
                    "UPDATE %s
                     SET status = 'drained', checkpoint = ?, worker_id = NULL, lease_expires_at = NULL, updated_at = CAST(? AS TIMESTAMPTZ)
                     WHERE projection_id = ? AND bucket_from = ?",
                    $this->segmentsTable(),
                ),
                [$checkpoint->serialize(), $now, $segment->projectionId, $segment->bucketFrom],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );

            $siblingRow = $this->connection->executeQuery(
                sprintf(
                    'SELECT status, checkpoint FROM %s WHERE projection_id = ? AND bucket_from = ? FOR UPDATE',
                    $this->segmentsTable(),
                ),
                [$segment->projectionId, $siblingFrom],
                [ParameterType::STRING, ParameterType::INTEGER],
            )->fetchAssociative();

            if (false === $siblingRow || 'drained' !== $siblingRow['status']) {
                $this->connection->commit();

                return;
            }

            $siblingCheckpoint = Checkpoint::deserialize((string) $siblingRow['checkpoint']);
            $parentCheckpoint = $this->minCheckpoint($checkpoint, $siblingCheckpoint);

            // Delete siblings before inserting parent: sibling-A shares bucket_from with the parent row.
            $this->connection->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE projection_id = ? AND bucket_from IN (?, ?)',
                    $this->segmentsTable(),
                ),
                [$segment->projectionId, $segment->bucketFrom, $siblingFrom],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );

            $this->connection->executeStatement(
                sprintf(
                    "INSERT INTO %s (projection_id, bucket_from, bucket_to, status, checkpoint, updated_at)
                     VALUES (?, ?, ?, 'available', ?, CAST(? AS TIMESTAMPTZ))",
                    $this->segmentsTable(),
                ),
                [$segment->projectionId, $parentFrom, $parentTo, $parentCheckpoint->serialize(), $now],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /** @return array{0: int, 1: int, 2: int} [parentFrom, parentTo, siblingFrom] */
    private function resolveMergeSibling(ProjectionSegment $segment): array
    {
        $rangeSize = $segment->bucketTo - $segment->bucketFrom + 1;
        $parentRangeSize = $rangeSize * 2;

        if (0 === $segment->bucketFrom % $parentRangeSize) {
            $parentFrom = $segment->bucketFrom;
            $parentTo = $segment->bucketFrom + $parentRangeSize - 1;
            $siblingFrom = $segment->bucketTo + 1;
        } else {
            $parentFrom = $segment->bucketFrom - $rangeSize;
            $parentTo = $segment->bucketTo;
            $siblingFrom = $parentFrom;
        }

        return [$parentFrom, $parentTo, $siblingFrom];
    }

    private function minCheckpoint(Checkpoint $a, Checkpoint $b): Checkpoint
    {
        return $a->gapFloor->isAtOrBefore($b->gapFloor) ? $a : $b;
    }

    /**
     * @return list<array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string}>
     */
    public function listSegments(string $projectionId): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT bucket_from, bucket_to, status, pending_operation
                 FROM %s WHERE projection_id = ? ORDER BY bucket_from ASC',
                $this->segmentsTable(),
            ),
            [$projectionId],
            [ParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'bucketFrom' => (int) $row['bucket_from'],
            'bucketTo' => (int) $row['bucket_to'],
            'status' => $row['status'],
            'pendingOperation' => $row['pending_operation'] ?? null,
        ], $rows);
    }

    private function segmentsTable(): string
    {
        return $this->tablePrefix . 'segments';
    }

    private function now(): DatePoint
    {
        return DatePoint::createFromInterface($this->clock->now());
    }
}
