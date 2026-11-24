<?php

declare(strict_types=1);

namespace Ultra\Schema;

use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\AddForeignKey;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\CreateExtension;
use Ultra\Schema\Operations\CreateFunction;
use Ultra\Schema\Operations\CreatePolicy;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropForeignKey;
use Ultra\Schema\Operations\DropIndex;
use Ultra\Schema\Operations\DropPolicy;
use Ultra\Schema\Operations\DropTable;
use Ultra\Schema\Operations\RenameColumn;
use Ultra\Schema\Operations\RenameIndex;
use Ultra\Schema\Operations\RenameTable;
use Ultra\Schema\Operations\SchemaOperation;
use Ultra\Schema\Operations\SetRowLevelSecurity;

final class PostgresSqlGenerator
{
    private const MANAGED_BY_ULTRA_COMMENT = 'managed-by:ultra';

    /**
     * @param list<SchemaOperation> $operations
     *
     * @return list<string>
     */
    public function generate(array $operations): array
    {
        $sql = [];

        foreach ($operations as $operation) {
            $statements = match (true) {
                $operation instanceof CreateExtension => sprintf(
                    'CREATE EXTENSION IF NOT EXISTS %s;',
                    $this->quoteIdentifier($operation->name),
                ),
                $operation instanceof CreateFunction => $this->createFunctionStatement(
                    $operation->function,
                    $operation->replace,
                ) . ';',
                $operation instanceof CreatePolicy => $this->createPolicy($operation),
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
                    'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)%s%s;',
                    $this->quoteIdentifier($operation->tableName),
                    $this->quoteIdentifier($operation->name),
                    $this->quoteIdentifiers(array_keys($operation->columns)),
                    $this->quoteIdentifier($operation->referencedTableName),
                    $this->quoteIdentifiers(array_values($operation->columns)),
                    ForeignKeyAction::NoAction === $operation->onDelete
                        ? ''
                        : ' ON DELETE ' . $operation->onDelete->value,
                    match ($operation->timing) {
                        ForeignKeyTiming::NotDeferrable => '',
                        ForeignKeyTiming::InitiallyImmediate => ' DEFERRABLE INITIALLY IMMEDIATE',
                        ForeignKeyTiming::InitiallyDeferred => ' DEFERRABLE INITIALLY DEFERRED',
                    },
                ),
                $operation instanceof AddIndex => $this->createIndex($operation->tableName, $operation->index),
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
                $operation instanceof DropPolicy => sprintf(
                    'DROP POLICY %s ON %s;',
                    $this->quoteIdentifier($operation->name),
                    $this->quoteIdentifier($operation->tableName),
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
                $operation instanceof SetRowLevelSecurity => sprintf(
                    'ALTER TABLE %s %s;',
                    $this->quoteIdentifier($operation->tableName),
                    match ($operation->mode) {
                        RowLevelSecurity::Disabled => 'DISABLE ROW LEVEL SECURITY, NO FORCE ROW LEVEL SECURITY',
                        RowLevelSecurity::Enabled => 'ENABLE ROW LEVEL SECURITY, NO FORCE ROW LEVEL SECURITY',
                        RowLevelSecurity::Forced => 'ENABLE ROW LEVEL SECURITY, FORCE ROW LEVEL SECURITY',
                    },
                ),
                default => throw new \LogicException(sprintf('Unsupported schema operation: %s', $operation::class)),
            };

            if (is_array($statements)) {
                array_push($sql, ...$statements);
            } else {
                $sql[] = $statements;
            }
        }

        return $sql;
    }

    private function createPolicy(CreatePolicy $operation): string
    {
        $policy = $operation->policy;

        return sprintf(
            'CREATE POLICY %s ON %s AS %s FOR %s%s%s%s;',
            $this->quoteIdentifier($operation->name),
            $this->quoteIdentifier($operation->tableName),
            $policy->mode->value,
            $policy->command->value,
            [] === $policy->roles ? '' : ' TO ' . $this->quoteIdentifiers($policy->roles),
            null === $policy->using ? '' : ' USING (' . $policy->using . ')',
            null === $policy->withCheck ? '' : ' WITH CHECK (' . $policy->withCheck . ')',
        );
    }

    /** @return list<string> */
    private function createIndex(string $tableName, IndexDefinition $index): array
    {
        return [
            null !== $index->rawSql
                ? $this->terminateStatement($index->rawSql)
                : $this->renderIndex($index, $this->quoteIdentifier($tableName)) . ';',
            $this->commentOnIndex($index->name),
        ];
    }

    /** Render an unterminated structured index using the storage adapter's trusted table reference. */
    public function createIndexIfNotExists(IndexDefinition $index, string $tableReference): string
    {
        return $this->renderIndex($index, $tableReference, ifNotExists: true);
    }

    private function renderIndex(IndexDefinition $index, string $tableReference, bool $ifNotExists = false): string
    {
        return sprintf(
            'CREATE %sINDEX %s%s ON %s%s (%s)%s',
            $index->unique ? 'UNIQUE ' : '',
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quoteIdentifier($index->name),
            $tableReference,
            null === $index->method ? '' : ' USING ' . $this->quoteIdentifier($index->method),
            $this->quoteIdentifiers($index->columns),
            null === $index->predicate ? '' : ' WHERE ' . $index->predicate,
        );
    }

    /** Render one unterminated function statement for bootstrap or migration SQL. */
    public function createFunctionStatement(PostgresFunctionDefinition $function, bool $replace = false): string
    {
        $arguments = [];
        foreach ($function->arguments as $name => $type) {
            $arguments[] = $this->quoteIdentifier($name) . ' ' . $type;
        }

        $tag = 'ultra_function';
        while (str_contains($function->body, '$' . $tag . '$')) {
            $tag .= '_';
        }
        $delimiter = '$' . $tag . '$';

        return sprintf(
            "CREATE %sFUNCTION %s(%s) RETURNS %s LANGUAGE %s AS %s\n%s\n%s",
            $replace ? 'OR REPLACE ' : '',
            $this->quoteIdentifier($function->name),
            implode(', ', $arguments),
            $function->returnType,
            $this->quoteIdentifier($function->language),
            $delimiter,
            $function->body,
            $delimiter,
        );
    }

    /** Render an unterminated bootstrap statement using the storage adapter's trusted table reference. */
    public function createTableIfNotExists(TableDefinition $table, string $tableReference): string
    {
        if (
            [] !== $table->policies
            || in_array($table->rowLevelSecurity, [RowLevelSecurity::Enabled, RowLevelSecurity::Forced], true)
        ) {
            throw new \LogicException('Use a schema migration to create a table with row-level security or policies.');
        }

        return $this->renderTable($table, 'CREATE TABLE IF NOT EXISTS ' . $tableReference);
    }

    private function renderTable(TableDefinition $table, string $createTable): string
    {
        $columnDefinitions = array_map($this->renderColumnDefinition(...), $table->columns);
        if ([] !== $table->primaryKey) {
            $columns = $table->columnsById();
            $names = array_map(static fn(string $id): string => $columns[$id]->name, $table->primaryKey);
            $columnDefinitions[] = sprintf('PRIMARY KEY (%s)', $this->quoteIdentifiers($names));
        }
        foreach ($table->checks as $name => $expression) {
            $columnDefinitions[] = sprintf('CONSTRAINT %s CHECK (%s)', $this->quoteIdentifier($name), $expression);
        }

        return sprintf("%s (\n    %s\n)", $createTable, implode(",\n    ", $columnDefinitions));
    }

    /** @return list<string> */
    private function createTable(CreateTable $operation): array
    {
        $table = $operation->table;
        $sql = [
            $this->renderTable($table, 'CREATE TABLE ' . $this->quoteIdentifier($table->name)) . ';',
            $this->commentOnTable($table->name),
        ];

        foreach ($table->columns as $column) {
            $sql[] = $this->commentOnColumn($table->name, $column->name);
        }
        foreach ($table->indexes as $index) {
            array_push($sql, ...$this->createIndex($table->name, $index));
        }

        return $sql;
    }

    private function alterColumn(AlterColumn $operation): string
    {
        $columnName = $this->quoteIdentifier($operation->columnName);
        $clauses = [];

        if (null !== $operation->type) {
            $clauses[] = sprintf('ALTER COLUMN %s TYPE %s', $columnName, $operation->type->sql);
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
            $column->type->sql,
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

    /** @return list<string> */
    private function addColumn(AddColumn $operation): array
    {
        return [
            sprintf(
                'ALTER TABLE %s ADD COLUMN %s;',
                $this->quoteIdentifier($operation->tableName),
                $this->renderColumnDefinition($operation->column),
            ),
            $this->commentOnColumn($operation->tableName, $operation->column->name),
        ];
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
}
