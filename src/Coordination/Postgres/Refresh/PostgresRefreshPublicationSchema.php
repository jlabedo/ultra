<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Refresh;

final readonly class PostgresRefreshPublicationSchema
{
    public function __construct(
        private string $table = 'coordination_refresh_revisions',
    ) {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            sprintf('CREATE TABLE IF NOT EXISTS %s (
                    channel   TEXT   PRIMARY KEY,
                    revision  BIGINT NOT NULL CHECK (revision > 0)
                )', $this->table),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [sprintf('DROP TABLE IF EXISTS %s', $this->table)];
    }
}
