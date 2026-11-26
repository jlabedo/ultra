<?php

declare(strict_types=1);

namespace Ultra\Coordination_Postgres\Refresh;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\ColumnType;

final readonly class PostgresRefreshPublicationSchema
{
    public function __construct(
        private string $table = 'coordination_refresh_revisions',
    ) {}

    public function tableDefinition(?string $id = null): TableDefinition
    {
        return new TableDefinition(
            $id ?? $this->table,
            strtolower($this->table),
            columns: [
                new ColumnDefinition('channel', type: ColumnType::Text),
                new ColumnDefinition('revision', type: ColumnType::Int64),
            ],
            primaryKey: ['channel'],
            checks: ['positive_revision' => 'revision > 0'],
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
