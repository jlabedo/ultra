<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Coordination;

final readonly class PostgresProjectionBucketActivitySchema
{
    public function __construct(private string $tablePrefix = 'projection_') {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    projection_id         TEXT        NOT NULL,
                    bucket_no             SMALLINT    NOT NULL,
                    counter               BIGINT      NOT NULL DEFAULT 0,
                    updated_at            TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
                    PRIMARY KEY (projection_id, bucket_no)
                )',
                $this->bucketActivityTable(),
            ),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [
            sprintf('DROP TABLE IF EXISTS %s', $this->bucketActivityTable()),
        ];
    }

    private function bucketActivityTable(): string
    {
        return $this->tablePrefix.'bucket_activity';
    }
}
