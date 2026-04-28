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

final class SchemaReconciler
{
    /**
     * @return list<SchemaOperation>
     */
    public function reconcile(SchemaDefinition $current, SchemaDefinition $target): array
    {
        $operations = [];

        $currentTables = $current->tablesById();
        $targetTables = $target->tablesById();

        foreach ($targetTables as $tableId => $targetTable) {
            $currentTable = $currentTables[$tableId] ?? null;

            if ($currentTable === null) {
                $operations[] = new CreateTable($targetTable);
                continue;
            }

            $resolvedTableName = $currentTable->name;

            if ($currentTable->name !== $targetTable->name) {
                $operations[] = new RenameTable($currentTable->name, $targetTable->name);
                $resolvedTableName = $targetTable->name;
            }

            $operations = [...$operations, ...$this->reconcileColumns($resolvedTableName, $currentTable, $targetTable)];
            $operations = [...$operations, ...$this->reconcileIndexes($resolvedTableName, $currentTable, $targetTable)];
        }

        foreach ($currentTables as $tableId => $currentTable) {
            if (isset($targetTables[$tableId])) {
                continue;
            }

            $operations[] = new DropTable($currentTable->name);
        }

        return $operations;
    }

    /**
     * @return list<SchemaOperation>
     */
    private function reconcileColumns(string $tableName, TableDefinition $current, TableDefinition $target): array
    {
        $operations = [];

        $currentColumns = $current->columnsById();
        $targetColumns = $target->columnsById();

        foreach ($targetColumns as $columnId => $targetColumn) {
            $currentColumn = $currentColumns[$columnId] ?? null;

            if ($currentColumn === null) {
                $operations[] = new AddColumn($tableName, $targetColumn);
                continue;
            }

            $resolvedColumnName = $currentColumn->name;

            if ($currentColumn->name !== $targetColumn->name) {
                $operations[] = new RenameColumn($tableName, $currentColumn->name, $targetColumn->name);
                $resolvedColumnName = $targetColumn->name;
            }

            if ($currentColumn->constraints !== $targetColumn->constraints) {
                throw new \LogicException(sprintf(
                    'Cannot reconcile changed constraints for column "%s"."%s".',
                    $tableName,
                    $resolvedColumnName,
                ));
            }

            if (! $currentColumn->equivalentTo($targetColumn)) {
                $operations[] = new AlterColumn(
                    tableName: $tableName,
                    columnName: $resolvedColumnName,
                    type: $currentColumn->type !== $targetColumn->type ? $targetColumn->type : null,
                    nullable: $currentColumn->nullable !== $targetColumn->nullable ? $targetColumn->nullable : null,
                    defaultExpression: $currentColumn->defaultExpression !== $targetColumn->defaultExpression
                        ? $targetColumn->defaultExpression
                        : null,
                    dropDefault: $currentColumn->defaultExpression !== null && $targetColumn->defaultExpression === null,
                );
            }
        }

        foreach ($currentColumns as $columnId => $currentColumn) {
            if (isset($targetColumns[$columnId])) {
                continue;
            }

            $operations[] = new DropColumn($tableName, $currentColumn->name);
        }

        return $operations;
    }

    /**
     * @return list<SchemaOperation>
     */
    private function reconcileIndexes(string $tableName, TableDefinition $current, TableDefinition $target): array
    {
        $operations = [];

        $currentIndexes = $current->indexesById();
        $targetIndexes = $target->indexesById();

        foreach ($targetIndexes as $indexId => $targetIndex) {
            $currentIndex = $currentIndexes[$indexId] ?? null;

            if ($currentIndex === null) {
                $operations[] = new AddIndex($tableName, $targetIndex);
                continue;
            }

            $resolvedIndexName = $currentIndex->name;

            if ($currentIndex->name !== $targetIndex->name) {
                $operations[] = new RenameIndex($tableName, $currentIndex->name, $targetIndex->name);
                $resolvedIndexName = $targetIndex->name;
            }

            if (! $currentIndex->equivalentTo($targetIndex)) {
                $operations[] = new DropIndex($tableName, $resolvedIndexName);
                $operations[] = new AddIndex($tableName, $targetIndex);
            }
        }

        foreach ($currentIndexes as $indexId => $currentIndex) {
            if (isset($targetIndexes[$indexId])) {
                continue;
            }

            $operations[] = new DropIndex($tableName, $currentIndex->name);
        }

        return $operations;
    }
}
