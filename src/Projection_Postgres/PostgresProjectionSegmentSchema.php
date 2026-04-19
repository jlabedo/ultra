<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

final readonly class PostgresProjectionSegmentSchema
{
    public function __construct(private string $tablePrefix = 'projection_') {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            sprintf(
                "CREATE TABLE IF NOT EXISTS %s (
                    projection_id     TEXT        NOT NULL,
                    bucket_from       SMALLINT    NOT NULL,
                    bucket_to         SMALLINT    NOT NULL,
                    status            TEXT        NOT NULL DEFAULT 'available',
                    pending_operation TEXT        NULL,
                    checkpoint        TEXT        NOT NULL DEFAULT '{\"gapFloor\":0,\"gapSeqNos\":[]}',
                    worker_id         TEXT        NULL,
                    lease_expires_at  TIMESTAMPTZ NULL,
                    updated_at        TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
                    PRIMARY KEY (projection_id, bucket_from)
                )",
                $this->segmentsTable(),
            ),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [
            sprintf('DROP TABLE IF EXISTS %s', $this->segmentsTable()),
        ];
    }

    public function segmentsTable(): string
    {
        return $this->tablePrefix . 'segments';
    }
}
