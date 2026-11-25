<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_PostgresQueue;

use Ultra\Schema\ColumnDefinition;
use Ultra\Schema\ColumnType;
use Ultra\Schema\IndexDefinition;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SqlType;
use Ultra\Schema\TableDefinition;

final readonly class PostgresAsyncQueueSchema
{
    public function __construct(
        private string $tablePrefix = PostgresAsyncQueue::DEFAULT_TABLE_PREFIX,
    ) {}

    public function definition(): SchemaDefinition
    {
        if (str_contains($this->tablePrefix, '.')) {
            throw new \InvalidArgumentException(
                'Async queue migrations require an unqualified table prefix. Select the PostgreSQL schema through search_path.',
            );
        }

        return new SchemaDefinition([$this->tableDefinition()]);
    }

    private function tableDefinition(): TableDefinition
    {
        $table = strtolower($this->messagesTable());
        $name = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

        return new TableDefinition(
            'async_queue.messages',
            $name,
            columns: [
                new ColumnDefinition('id', type: ColumnType::Text),
                new ColumnDefinition('queue_name', type: ColumnType::Text),
                new ColumnDefinition('body', type: SqlType::fromSql('bytea')),
                new ColumnDefinition('headers', type: ColumnType::Jsonb, defaultExpression: "'{}'::jsonb"),
                new ColumnDefinition('attributes', type: ColumnType::Jsonb, defaultExpression: "'{}'::jsonb"),
                new ColumnDefinition('schedule_key', type: ColumnType::Text, nullable: true),
                new ColumnDefinition('available_at', type: ColumnType::TimestampWithTimeZone),
                new ColumnDefinition('lease_id', type: ColumnType::Text, nullable: true),
                new ColumnDefinition('leased_until', type: ColumnType::TimestampWithTimeZone, nullable: true),
                new ColumnDefinition('leased_by', type: ColumnType::Text, nullable: true),
                new ColumnDefinition('attempts', type: ColumnType::Int32, defaultExpression: '0'),
                new ColumnDefinition(
                    'created_at',
                    type: ColumnType::TimestampWithTimeZone,
                    defaultExpression: 'statement_timestamp()',
                ),
                new ColumnDefinition(
                    'updated_at',
                    type: ColumnType::TimestampWithTimeZone,
                    defaultExpression: 'statement_timestamp()',
                ),
            ],
            indexes: [
                new IndexDefinition(
                    'schedule_key',
                    $name . '_schedule_key_idx',
                    columns: ['queue_name', 'schedule_key'],
                    predicate: 'schedule_key IS NOT NULL',
                ),
                new IndexDefinition(
                    'available_unleased',
                    $name . '_available_unleased_idx',
                    columns: ['queue_name', 'available_at', 'id'],
                    predicate: 'lease_id IS NULL',
                ),
                new IndexDefinition(
                    'available_leased',
                    $name . '_available_leased_idx',
                    columns: ['queue_name', 'leased_until', 'available_at', 'id'],
                    predicate: 'lease_id IS NOT NULL',
                ),
            ],
            primaryKey: ['id'],
        );
    }

    /** @return list<string> */
    public function createStatements(): array
    {
        $table = $this->tableDefinition();
        $reference = $this->messagesTable();
        $generator = new PostgresSqlGenerator();

        return [
            $generator->createTableIfNotExists($table, $reference),
            ...array_map(static fn(IndexDefinition $index): string => $generator->createIndexIfNotExists(
                $index,
                $reference,
            ), $table->indexes),
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
        return $this->tablePrefix . 'messages';
    }
}
