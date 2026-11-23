<?php

declare(strict_types=1);

namespace Ultra\Schema;

use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\AddForeignKey;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropForeignKey;
use Ultra\Schema\Operations\DropIndex;
use Ultra\Schema\Operations\DropTable;
use Ultra\Schema\Operations\RenameColumn;
use Ultra\Schema\Operations\RenameIndex;
use Ultra\Schema\Operations\RenameTable;
use Ultra\Schema\Operations\SchemaOperation;

final class PostgresSqlGenerator
{
    private const MANAGED_BY_ULTRA_COMMENT = 'managed by: ultra';

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
                $operation instanceof DropTable => sprintf(
                    'DROP TABLE %s;',
                    $this->quoteIdentifier($operation->tableName),
                ),
                $operation instanceof RenameTable => sprintf(
                    'ALTER TABLE %s RENAME TO %s;',
                    $this->quoteIdentifier($operation->fromName),
                    $this->quoteIdentifier($operation->toName),
                ),
                $operation instanceof AddColumn => $this->addColumn($operation),
                $operation instanceof AddForeignKey => sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s);',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->name),
                    $this->quoteIdentifiers(array_keys($operation->columns)),
                    $this->quoteIdentifier($operation->referencedTableName),
                    $this->quoteIdentifiers(array_values($operation->columns)),
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
                $operation instanceof DropForeignKey => sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT %s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->name),
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
        if (null !== $operation->index->rawSql) {
            return sprintf(
                '%s%s%s',
                $this->terminateStatement($operation->index->rawSql),
                $this->lineSeparator(),
                $this->commentOnIndex($operation->index->name),
            );
        }

        $columns = array_map($this->quoteIdentifier(...), $operation->index->columns);

        return sprintf(
            '%s%s%s',
            sprintf(
                'CREATE %sINDEX %s ON %s (%s);',
                $operation->index->unique ? 'UNIQUE ' : '',
                $this->quoteIdentifier($operation->index->name),
                $this->quoteIdentifier($operation->tableName),
                implode(', ', $columns),
            ),
            $this->lineSeparator(),
            $this->commentOnIndex($operation->index->name),
        );
    }

    private function createTable(CreateTable $operation): string
    {
        $table = $operation->table;
        $columnDefinitions = array_map($this->renderColumnDefinition(...), $table->columns);
        if ([] !== $table->primaryKey) {
            $columns = $table->columnsById();
            $names = array_map(static fn(string $id): string => $columns[$id]->name, $table->primaryKey);
            $columnDefinitions[] = sprintf('PRIMARY KEY (%s)', $this->quoteIdentifiers($names));
        }

        $sql = sprintf(
            "CREATE TABLE %s (\n    %s\n);",
            $this->quoteIdentifier($operation->table->name),
            implode(",\n    ", $columnDefinitions),
        );

        $comments = [
            $this->commentOnTable($operation->table->name),
        ];

        foreach ($operation->table->columns as $column) {
            $comments[] = $this->commentOnColumn($operation->table->name, $column->name);
        }

        return sprintf('%s%s%s', $sql, $this->lineSeparator(), implode($this->lineSeparator(), $comments));
    }

    private function alterColumn(AlterColumn $operation): string
    {
        $columnName = $this->quoteIdentifier($operation->columnName);
        $clauses = [];

        if (null !== $operation->type) {
            $clauses[] = sprintf('ALTER COLUMN %s TYPE %s', $columnName, $this->renderType($operation->type));
        }

        if (null !== $operation->nullable) {
            $clauses[] = $operation->nullable
                ? sprintf('ALTER COLUMN %s DROP NOT NULL', $columnName)
                : sprintf('ALTER COLUMN %s SET NOT NULL', $columnName);
        }

        if ($operation->dropDefault) {
            $clauses[] = sprintf('ALTER COLUMN %s DROP DEFAULT', $columnName);
        }
        if (!$operation->dropDefault && null !== $operation->defaultExpression) {
            $clauses[] = sprintf('ALTER COLUMN %s SET DEFAULT %s', $columnName, $operation->defaultExpression);
        }

        if ([] === $clauses) {
            throw new \LogicException(sprintf(
                'AlterColumn operation for "%s"."%s" does not contain any effective change.',
                $operation->tableName,
                $operation->columnName,
            ));
        }

        return sprintf('ALTER TABLE %s %s;', $this->quoteIdentifier($operation->tableName), implode(', ', $clauses));
    }

    private function renderColumnDefinition(ColumnDefinition $column): string
    {
        $parts = [
            $this->quoteIdentifier($column->name),
            $this->renderType($column->type),
        ];

        if (!$column->nullable) {
            $parts[] = 'NOT NULL';
        }

        if (null !== $column->defaultExpression) {
            $parts[] = sprintf('DEFAULT %s', $column->defaultExpression);
        }

        array_push($parts, ...$column->constraints);

        return implode(' ', $parts);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return sprintf('"%s"', str_replace('"', '""', $identifier));
    }

    private function renderType(ColumnType|string $type): string
    {
        return match ($type) {
            ColumnType::Text => 'text',
            ColumnType::Int32 => 'integer',
            default => $type,
        };
    }

    /** @param list<string> $identifiers */
    private function quoteIdentifiers(array $identifiers): string
    {
        return implode(', ', array_map($this->quoteIdentifier(...), $identifiers));
    }

    private function terminateStatement(string $sql): string
    {
        $sql = trim($sql);

        if ('' === $sql) {
            throw new \LogicException('Raw SQL statement cannot be empty.');
        }

        return str_ends_with($sql, ';') ? $sql : sprintf('%s;', $sql);
    }

    private function addColumn(AddColumn $operation): string
    {
        return sprintf(
            '%s%s%s',
            sprintf(
                'ALTER TABLE %s ADD COLUMN %s;',
                $this->quoteIdentifier($operation->tableName),
                $this->renderColumnDefinition($operation->column),
            ),
            $this->lineSeparator(),
            $this->commentOnColumn($operation->tableName, $operation->column->name),
        );
    }

    private function commentOnTable(string $tableName): string
    {
        return sprintf(
            'COMMENT ON TABLE %s IS %s;',
            $this->quoteIdentifier($tableName),
            $this->quoteLiteral(self::MANAGED_BY_ULTRA_COMMENT),
        );
    }

    private function commentOnColumn(string $tableName, string $columnName): string
    {
        return sprintf(
            'COMMENT ON COLUMN %s.%s IS %s;',
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier($columnName),
            $this->quoteLiteral(self::MANAGED_BY_ULTRA_COMMENT),
        );
    }

    private function commentOnIndex(string $indexName): string
    {
        return sprintf(
            'COMMENT ON INDEX %s IS %s;',
            $this->quoteIdentifier($indexName),
            $this->quoteLiteral(self::MANAGED_BY_ULTRA_COMMENT),
        );
    }

    private function quoteLiteral(string $literal): string
    {
        return sprintf('\'%s\'', str_replace('\'', '\'\'', $literal));
    }

    private function lineSeparator(): string
    {
        return "\n";
    }
}
