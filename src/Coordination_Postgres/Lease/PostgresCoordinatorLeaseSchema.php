<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Lease;

final readonly class PostgresCoordinatorLeaseSchema
{
    public function __construct(private string $table = 'coordination_leases') {}

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    coordinator_key      TEXT        NOT NULL PRIMARY KEY,
                    worker_id            TEXT        NULL,
                    lease_expires_at     TIMESTAMPTZ NULL,
                    wake_enqueued_until  TIMESTAMPTZ NULL,
                    updated_at           TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp()
                )',
                $this->table,
            ),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [sprintf('DROP TABLE IF EXISTS %s', $this->table)];
    }
}
