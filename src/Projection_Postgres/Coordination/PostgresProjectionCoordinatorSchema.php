<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres\Coordination;

final readonly class PostgresProjectionCoordinatorSchema
{
    public function __construct(private string $tablePrefix = 'projection_') {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            sprintf(
                "CREATE TABLE IF NOT EXISTS %s (
                    projection_id        TEXT        NOT NULL PRIMARY KEY,
                    worker_id            TEXT        NULL,
                    lease_expires_at     TIMESTAMPTZ NULL,
                    wake_enqueued_until  TIMESTAMPTZ NULL,
                    updated_at           TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp()
                )",
                $this->coordinatorsTable(),
            ),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [
            sprintf('DROP TABLE IF EXISTS %s', $this->coordinatorsTable()),
        ];
    }

    private function coordinatorsTable(): string
    {
        return $this->tablePrefix . 'coordinators';
    }
}
