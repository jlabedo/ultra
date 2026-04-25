<?php

declare(strict_types=1);

namespace Ultra\Schema;

use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropIndex;
use Ultra\Schema\Operations\DropTable;
use Ultra\Schema\Operations\RenameColumn;
use Ultra\Schema\Operations\RenameIndex;
use Ultra\Schema\Operations\RenameTable;
use Ultra\Schema\Operations\SchemaOperation;

final class PostgresSqlGenerator
{
    /**
     * @param list<SchemaOperation> $operations
     *
     * @return list<string>
     */
    public function generate(array $operations): array
    {
        $sql = [];

        foreach ($operations as $operation) {
            $sql[] = match (true) {
                $operation instanceof CreateTable => $this->createTable($operation),
                $operation instanceof DropTable => sprintf('DROP TABLE %s;', $this->quoteIdentifier($operation->tableName)),
                $operation instanceof RenameTable => sprintf(
                    'ALTER TABLE %s RENAME TO %s;',
                    $this->quoteIdentifier($operation->fromName),
                    $this->quoteIdentifier($operation->toName),
                ),
                $operation instanceof AddColumn => sprintf(
                    'ALTER TABLE %s ADD COLUMN %s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->renderColumnDefinition($operation->column),
                ),
                $operation instanceof AddIndex => $this->createIndex($operation),
                $operation instanceof DropColumn => sprintf(
                    'ALTER TABLE %s DROP COLUMN %s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->columnName),
                ),
                $operation instanceof DropIndex => sprintf(
                    'DROP INDEX %s;',
                    $this->quoteIdentifier($operation->indexName),
                ),
                $operation instanceof RenameColumn => sprintf(
                    'ALTER TABLE %s RENAME COLUMN %s TO %s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->fromName),
                    $this->quoteIdentifier($operation->toName),
                ),
                $operation instanceof RenameIndex => sprintf(
                    'ALTER INDEX %s RENAME TO %s;',
                    $this->quoteIdentifier($operation->fromName),
                    $this->quoteIdentifier($operation->toName),
                ),
                $operation instanceof AlterColumn => $this->alterColumn($operation),
                default => throw new \LogicException(sprintf('Unsupported schema operation: %s', $operation::class)),
            };
        }

        return $sql;
    }

    private function createIndex(AddIndex $operation): string
    {
        if ($operation->index->rawSql !== null) {
            return $this->terminateStatement($operation->index->rawSql);
        }

        $columns = array_map($this->quoteIdentifier(...), $operation->index->columns);

        return sprintf(
            'CREATE %sINDEX %s ON %s (%s);',
            $operation->index->unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($operation->index->name),
            $this->quoteIdentifier($operation->tableName),
            implode(', ', $columns),
        );
    }

    private function createTable(CreateTable $operation): string
    {
        $columnDefinitions = array_map($this->renderColumnDefinition(...), $operation->table->columns);

        return sprintf(
            "CREATE TABLE %s (\n    %s\n);",
            $this->quoteIdentifier($operation->table->name),
            implode(",\n    ", $columnDefinitions),
        );
    }

    private function alterColumn(AlterColumn $operation): string
    {
        $columnName = $this->quoteIdentifier($operation->columnName);
        $clauses = [];

        if ($operation->type !== null) {
            $clauses[] = sprintf('ALTER COLUMN %s TYPE %s', $columnName, $operation->type);
        }

        if ($operation->nullable !== null) {
            $clauses[] = $operation->nullable
                ? sprintf('ALTER COLUMN %s DROP NOT NULL', $columnName)
                : sprintf('ALTER COLUMN %s SET NOT NULL', $columnName);
        }

        if ($operation->dropDefault) {
            $clauses[] = sprintf('ALTER COLUMN %s DROP DEFAULT', $columnName);
        } elseif ($operation->defaultExpression !== null) {
            $clauses[] = sprintf('ALTER COLUMN %s SET DEFAULT %s', $columnName, $operation->defaultExpression);
        }

        if ($clauses === []) {
            throw new \LogicException(sprintf(
                'AlterColumn operation for "%s"."%s" does not contain any effective change.',
                $operation->tableName,
                $operation->columnName,
            ));
        }

        return sprintf(
            'ALTER TABLE %s %s;',
            $this->quoteIdentifier($operation->tableName),
            implode(', ', $clauses),
        );
    }

    private function renderColumnDefinition(ColumnDefinition $column): string
    {
        $parts = [
            $this->quoteIdentifier($column->name),
            $column->type,
        ];

        if (! $column->nullable) {
            $parts[] = 'NOT NULL';
        }

        if ($column->defaultExpression !== null) {
            $parts[] = sprintf('DEFAULT %s', $column->defaultExpression);
        }

        return implode(' ', $parts);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return sprintf('"%s"', str_replace('"', '""', $identifier));
    }

    private function terminateStatement(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '') {
            throw new \LogicException('Raw SQL statement cannot be empty.');
        }

        return str_ends_with($sql, ';') ? $sql : sprintf('%s;', $sql);
    }
}
