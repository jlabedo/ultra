<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\TableDefinition;
use Ultra\Sql\ColumnType;
use Ultra\Sql\SqlType;

final readonly class PostgresProjectionSegmentSchema
{
    public function __construct(
        private string $tablePrefix = PostgresProjectionImplementation::DEFAULT_TABLE_PREFIX,
    ) {}

    public function definition(): SchemaDefinition
    {
        return new SchemaDefinition([
            new PostgresProjectionStateSchema($this->tablePrefix)->tableDefinition(),
            $this->tableDefinition(),
        ]);
    }

    private function tableDefinition(): TableDefinition
    {
        return new TableDefinition(
            'projection.segments',
            strtolower($this->segmentsTable()),
            columns: [
                new ColumnDefinition('projection_id', type: ColumnType::Text),
                new ColumnDefinition('bucket_from', type: SqlType::fromSql('smallint')),
                new ColumnDefinition('bucket_to', type: SqlType::fromSql('smallint')),
                new ColumnDefinition(
                    'checkpoint',
                    type: ColumnType::Text,
                    defaultExpression: "'{\"gapFloor\":0,\"gapSeqNos\":[]}'",
                ),
                new ColumnDefinition('worker_id', type: ColumnType::Text, nullable: true),
                new ColumnDefinition('lease_expires_at', type: ColumnType::TimestampWithTimeZone, nullable: true),
                new ColumnDefinition(
                    'updated_at',
                    type: ColumnType::TimestampWithTimeZone,
                    defaultExpression: 'statement_timestamp()',
                ),
            ],
            primaryKey: ['projection_id', 'bucket_from'],
        );
    }

    /** @return list<string> */
    public function createStatements(): array
    {
        return [
            ...new PostgresProjectionStateSchema($this->tablePrefix)->createStatements(),
            new PostgresSqlGenerator()->createTableIfNotExists($this->tableDefinition(), $this->segmentsTable()),
        ];
    }

    /** @return list<string> */
    public function dropStatements(): array
    {
        return [
            sprintf('DROP TABLE IF EXISTS %s', $this->segmentsTable()),
            ...new PostgresProjectionStateSchema($this->tablePrefix)->dropStatements(),
        ];
    }

    public function segmentsTable(): string
    {
        return $this->tablePrefix . 'segments';
    }
}
