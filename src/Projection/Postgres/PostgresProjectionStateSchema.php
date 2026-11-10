<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

final readonly class PostgresProjectionStateSchema
{
    public function __construct(
        private string $tablePrefix = 'projection_',
    ) {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [sprintf("CREATE TABLE IF NOT EXISTS %s (
            projection_id TEXT PRIMARY KEY,
            generation BIGINT NOT NULL,
            phase TEXT NOT NULL CHECK (phase IN ('building', 'live')),
            fingerprint TEXT NOT NULL
        )", $this->tablePrefix . 'states')];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return ['DROP TABLE IF EXISTS ' . $this->tablePrefix . 'states'];
    }
}
