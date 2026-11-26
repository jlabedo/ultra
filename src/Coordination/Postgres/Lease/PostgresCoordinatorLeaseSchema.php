<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Lease;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\ColumnType;

final readonly class PostgresCoordinatorLeaseSchema
{
    public function __construct(
        private string $table = 'coordination_leases',
    ) {}

    public function tableDefinition(?string $id = null): TableDefinition
    {
        return new TableDefinition(
            $id ?? $this->table,
            strtolower($this->table),
            columns: [
                new ColumnDefinition('coordinator_key', type: ColumnType::Text),
                new ColumnDefinition('worker_id', type: ColumnType::Text, nullable: true),
                new ColumnDefinition('lease_expires_at', type: ColumnType::TimestampWithTimeZone, nullable: true),
                new ColumnDefinition('wake_enqueued_until', type: ColumnType::TimestampWithTimeZone, nullable: true),
                new ColumnDefinition(
                    'updated_at',
                    type: ColumnType::TimestampWithTimeZone,
                    defaultExpression: 'statement_timestamp()',
                ),
            ],
            primaryKey: ['coordinator_key'],
        );
    }

    /** @return list<string> */
    public function createStatements(): array
    {
        return [new PostgresSqlGenerator()->createTableIfNotExists($this->tableDefinition(), $this->table)];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [sprintf('DROP TABLE IF EXISTS %s', $this->table)];
    }
}
