<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Tracking;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\TableDefinition;

final readonly class PostgresDirtyKeyTrackerSchema
{
    public function __construct(
        private string $table = 'coordination_dirty_keys',
    ) {}

    public function tableDefinition(?string $id = null): TableDefinition
    {
        return new TableDefinition(
            $id ?? $this->table,
            strtolower($this->table),
            columns: [
                new ColumnDefinition('channel', type: ColumnType::Text),
                new ColumnDefinition('key', type: ColumnType::Text),
                new ColumnDefinition('counter', type: ColumnType::Int64, defaultExpression: '0'),
                new ColumnDefinition('updated_at', type: 'timestamptz', defaultExpression: 'statement_timestamp()'),
            ],
            primaryKey: ['channel', 'key'],
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
