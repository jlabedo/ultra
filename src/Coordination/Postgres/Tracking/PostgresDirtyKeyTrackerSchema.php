<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Tracking;

final readonly class PostgresDirtyKeyTrackerSchema
{
    public function __construct(
        private string $table = 'coordination_dirty_keys',
    ) {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            sprintf('CREATE TABLE IF NOT EXISTS %s (
                    channel     TEXT        NOT NULL,
                    key         TEXT        NOT NULL,
                    counter     BIGINT      NOT NULL DEFAULT 0,
                    updated_at  TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
                    PRIMARY KEY (channel, key)
                )', $this->table),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [sprintf('DROP TABLE IF EXISTS %s', $this->table)];
    }
}
