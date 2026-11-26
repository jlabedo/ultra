<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\ColumnType;

final readonly class PostgresProjectionStateSchema
{
    public function __construct(
        private string $tablePrefix = PostgresProjectionImplementation::DEFAULT_TABLE_PREFIX,
    ) {}

    public function tableDefinition(): TableDefinition
    {
        $name = strtolower($this->tablePrefix . 'states');

        return new TableDefinition(
            'projection.states',
            $name,
            columns: [
                new ColumnDefinition('projection_id', type: ColumnType::Text),
                new ColumnDefinition('generation', type: ColumnType::Int64),
                new ColumnDefinition('phase', type: ColumnType::Text),
                new ColumnDefinition('fingerprint', type: ColumnType::Text),
            ],
            primaryKey: ['projection_id'],
            checks: ['valid_phase' => "phase IN ('building', 'live')"],
        );
    }

    /** @return list<string> */
    public function createStatements(): array
    {
        return [new PostgresSqlGenerator()->createTableIfNotExists(
            $this->tableDefinition(),
            $this->tablePrefix . 'states',
        )];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return ['DROP TABLE IF EXISTS ' . $this->tablePrefix . 'states'];
    }
}
