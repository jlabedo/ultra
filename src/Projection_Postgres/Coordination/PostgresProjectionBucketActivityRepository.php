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
                "INSERT INTO %s (projection_id, bucket_no, counter, acknowledged_counter)
                 VALUES (?, ?, 1, 0)
                 ON CONFLICT (projection_id, bucket_no)
                 DO UPDATE SET counter = %s.counter + 1, updated_at = statement_timestamp()",
                $this->bucketActivityTable(),
                $this->bucketActivityTable(),
            ),
            [$projectionId, $bucket],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function affectedBuckets(int $limit = 256): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT projection_id, bucket_no, counter, acknowledged_counter
                 FROM %s
                 WHERE counter > acknowledged_counter
                 ORDER BY projection_id ASC, counter DESC, bucket_no ASC
                 LIMIT ?',
                $this->bucketActivityTable(),
            ),
            [$limit],
            [ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionBucketActivity => new ProjectionBucketActivity(
                $row['projection_id'],
                (int) $row['bucket_no'],
                (int) $row['counter'],
                (int) $row['acknowledged_counter'],
            ),
            $rows,
        );
    }

    public function pendingBuckets(string $projectionId, int $limit = 32): array
    {
        $rows = $this->connection->executeQuery(
            sprintf(
                'SELECT projection_id, bucket_no, counter, acknowledged_counter
                 FROM %s
                 WHERE projection_id = ? AND counter > acknowledged_counter
                 ORDER BY counter DESC, bucket_no ASC
                 LIMIT ?',
                $this->bucketActivityTable(),
            ),
            [$projectionId, $limit],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(
            static fn(array $row): ProjectionBucketActivity => new ProjectionBucketActivity(
                $row['projection_id'],
                (int) $row['bucket_no'],
                (int) $row['counter'],
                (int) $row['acknowledged_counter'],
            ),
            $rows,
        );
    }

    public function acknowledge(string $projectionId, int $bucket, int $counter): void
    {
        $this->connection->executeStatement(
            sprintf(
                "UPDATE %s
                 SET acknowledged_counter = GREATEST(acknowledged_counter, ?), updated_at = statement_timestamp()
                 WHERE projection_id = ? AND bucket_no = ?",
                $this->bucketActivityTable(),
            ),
            [$counter, $projectionId, $bucket],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    private function bucketActivityTable(): string
    {
        return $this->tablePrefix . 'bucket_activity';
    }
}
