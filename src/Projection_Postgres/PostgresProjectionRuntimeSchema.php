<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

final readonly class PostgresProjectionRuntimeSchema
{
    public function __construct(
        private string $tablePrefix = 'projection_',
    ) {}

    /**
     * @return list<string>
     */
    public function createStatements(): array
    {
        return [
            sprintf(
                'CREATE TABLE %1$sstate (
                    projection_name TEXT NOT NULL,
                    projection_version INTEGER NOT NULL,
                    lane TEXT NOT NULL,
                    last_event_id BIGINT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
                    PRIMARY KEY (projection_name, projection_version, lane)
                )',
                $this->tablePrefix,
            ),
            sprintf(
                'CREATE TABLE %1$spartitions (
                    projection_name TEXT NOT NULL,
                    projection_version INTEGER NOT NULL,
                    partition_key TEXT NOT NULL,
                    last_event_id BIGINT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
                    PRIMARY KEY (projection_name, projection_version, partition_key)
                )',
                $this->tablePrefix,
            ),
            sprintf(
                'CREATE TABLE %1$sinbox (
                    projection_name TEXT NOT NULL,
                    projection_version INTEGER NOT NULL,
                    partition_key TEXT NOT NULL,
                    event_id BIGINT NOT NULL,
                    PRIMARY KEY (projection_name, projection_version, partition_key, event_id)
                )',
                $this->tablePrefix,
            ),
            sprintf(
                'CREATE INDEX %1$sinbox_projection_event_id_idx ON %1$sinbox (projection_name, projection_version, event_id)',
                $this->tablePrefix,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function dropStatements(): array
    {
        return [
            sprintf('DROP TABLE IF EXISTS %sinbox', $this->tablePrefix),
            sprintf('DROP TABLE IF EXISTS %spartitions', $this->tablePrefix),
            sprintf('DROP TABLE IF EXISTS %sstate', $this->tablePrefix),
        ];
    }
}
