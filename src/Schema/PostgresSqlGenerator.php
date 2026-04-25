<?php

declare(strict_types=1);

namespace Ultra\Schema;

use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropTable;
use Ultra\Schema\Operations\RenameColumn;
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
                $operation instanceof DropColumn => sprintf(
                    'ALTER TABLE %s DROP COLUMN %s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->columnName),
                ),
                $operation instanceof RenameColumn => sprintf(
                    'ALTER TABLE %s RENAME COLUMN %s TO %s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->fromName),
                    $this->quoteIdentifier($operation->toName),
                ),
                $operation instanceof AlterColumn => $this->alterColumn($operation),
                default => throw new \LogicException(sprintf('Unsupported schema operation: %s', $operation::class)),
            };
        }

        return $sql;
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
}
