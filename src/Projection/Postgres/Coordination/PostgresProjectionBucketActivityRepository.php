<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Coordination;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ultra\Projection\Coordination\ProjectionBucketActivity;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;

final readonly class PostgresProjectionBucketActivityRepository implements ProjectionBucketActivityRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private string $tablePrefix = 'projection_',
    ) {}

    public function bump(string $projectionId, int $bucket): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (projection_id, bucket_no, counter)
                 VALUES (?, ?, 1)
                 ON CONFLICT (projection_id, bucket_no)
                 DO UPDATE SET counter = %s.counter + 1, updated_at = statement_timestamp()',
                $this->bucketActivityTable(),
                $this->bucketActivityTable(),
            ),
            [$projectionId, $bucket],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function affectedBuckets(int $limit = 256): array
    {
        $rows = $this->connection
            ->executeQuery(
                sprintf('SELECT projection_id, bucket_no, counter
                 FROM %s
                 WHERE counter > 0
                 ORDER BY projection_id ASC, counter DESC, bucket_no ASC
                 LIMIT ?', $this->bucketActivityTable()),
                [$limit],
                [ParameterType::INTEGER],
            )
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionBucketActivity => new ProjectionBucketActivity(
                (string) $row['projection_id'],
                (int) $row['bucket_no'],
                (int) $row['counter'],
            ),
            $rows,
        );
    }

    public function pendingBucketsForSegment(string $projectionId, int $bucketFrom, int $bucketTo): array
    {
        $rows = $this->connection
            ->executeQuery(
                sprintf('SELECT projection_id, bucket_no, counter
                 FROM %s
                 WHERE projection_id = ?
                   AND bucket_no >= ?
                   AND bucket_no <= ?
                   AND counter > 0
                 ORDER BY counter DESC, bucket_no ASC', $this->bucketActivityTable()),
                [$projectionId, $bucketFrom, $bucketTo],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            )
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionBucketActivity => new ProjectionBucketActivity(
                (string) $row['projection_id'],
                (int) $row['bucket_no'],
                (int) $row['counter'],
            ),
            $rows,
        );
    }

    public function decrementSeenBuckets(string $projectionId, array $seenCounters): int
    {
        $seenCounters = array_filter($seenCounters, static fn(int $counter): bool => $counter > 0);
        if ([] === $seenCounters) {
            return 0;
        }

        $values = [];
        $params = [];
        $types = [];
        foreach ($seenCounters as $bucket => $counter) {
            $values[] = '(CAST(? AS SMALLINT), CAST(? AS INTEGER))';
            $params[] = (int) $bucket;
            $params[] = $counter;
            $types[] = ParameterType::INTEGER;
            $types[] = ParameterType::INTEGER;
        }

        $params[] = $projectionId;
        $types[] = ParameterType::STRING;

        return (int) $this->connection->executeStatement(
            sprintf(
                'UPDATE %s AS bucket_activity
                 SET counter = GREATEST(bucket_activity.counter - seen.seen_counter, 0),
                     updated_at = statement_timestamp()
                 FROM (VALUES %s) AS seen(bucket_no, seen_counter)
                 WHERE bucket_activity.projection_id = ?
                   AND bucket_activity.bucket_no = seen.bucket_no',
                $this->bucketActivityTable(),
                implode(', ', $values),
            ),
            $params,
            $types,
        );
    }

    private function bucketActivityTable(): string
    {
        return $this->tablePrefix . 'bucket_activity';
    }
}
