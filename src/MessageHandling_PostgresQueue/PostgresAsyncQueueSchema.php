<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

final readonly class PostgresAsyncQueueSchema
{
    public function __construct(private string $tablePrefix = 'async_queue_') {}

    /**
     * @return list<string>
     */
    public function createStatements(): array
    {
        return [
            sprintf(
                "CREATE TABLE IF NOT EXISTS %s (
                    id             TEXT        NOT NULL PRIMARY KEY,
                    queue_name     TEXT        NOT NULL,
                    body           BYTEA       NOT NULL,
                    headers        JSONB       NOT NULL DEFAULT '{}'::jsonb,
                    attributes     JSONB       NOT NULL DEFAULT '{}'::jsonb,
                    available_at   TIMESTAMPTZ NOT NULL,
                    lease_id       TEXT        NULL,
                    leased_until   TIMESTAMPTZ NULL,
                    leased_by      TEXT        NULL,
                    attempts       INT         NOT NULL DEFAULT 0,
                    created_at     TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp(),
                    updated_at     TIMESTAMPTZ NOT NULL DEFAULT statement_timestamp()
                )",
                $this->messagesTable(),
            ),
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s_available_unleased_idx
                 ON %s (queue_name, available_at, id)
                 WHERE lease_id IS NULL',
                $this->tablePrefix.'messages',
                $this->messagesTable(),
            ),
            sprintf(
                'CREATE INDEX IF NOT EXISTS %s_available_leased_idx
                 ON %s (queue_name, leased_until, available_at, id)
                 WHERE lease_id IS NOT NULL',
                $this->tablePrefix.'messages',
                $this->messagesTable(),
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function dropStatements(): array
    {
        return [
            sprintf('DROP TABLE IF EXISTS %s', $this->messagesTable()),
        ];
    }

    public function messagesTable(): string
    {
        return $this->tablePrefix.'messages';
    }
}
