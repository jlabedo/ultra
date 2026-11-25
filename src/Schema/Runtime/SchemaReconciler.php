<?php

declare(strict_types=1);

namespace Ultra\Schema;

use Ultra\Schema\Operations\AddColumn;
use Ultra\Schema\Operations\AddForeignKey;
use Ultra\Schema\Operations\AddIndex;
use Ultra\Schema\Operations\AlterColumn;
use Ultra\Schema\Operations\CreateExtension;
use Ultra\Schema\Operations\CreateFunction;
use Ultra\Schema\Operations\CreateTable;
use Ultra\Schema\Operations\DropColumn;
use Ultra\Schema\Operations\DropForeignKey;
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
        $current->validateForeignKeys();
        $target->validateForeignKeys();
        $currentTables = $current->tablesById();
        $targetTables = $target->tablesById();

        $operations = [];
        foreach (array_unique(array_diff($target->requiredExtensions, $current->requiredExtensions)) as $extension) {
            $operations[] = new CreateExtension($extension);
        }

        foreach ($currentTables as $tableId => $currentTable) {
            foreach ($currentTable->foreignKeys as $name => $foreignKey) {
                if ($foreignKey->equivalentTo($targetTables[$tableId]->foreignKeys[$name] ?? null)) {
                    continue;
                }
                $operations[] = new DropForeignKey($currentTable->name, $name);
            }
        }

        foreach ($targetTables as $tableId => $targetTable) {
            $currentTable = $currentTables[$tableId] ?? null;

            if (null === $currentTable) {
                $operations[] = new CreateTable($targetTable);

                continue;
            }

            if ($currentTable->primaryKey !== $targetTable->primaryKey) {
                throw new \LogicException(sprintf(
                    'Cannot reconcile a changed primary key for table "%s".',
                    $currentTable->name,
                ));
            }
            if (
                count($currentTable->checks) !== count($targetTable->checks)
                || [] !== array_diff_assoc($currentTable->checks, $targetTable->checks)
            ) {
                throw new \LogicException(sprintf(
                    'Cannot reconcile changed check constraints for table "%s".',
                    $currentTable->name,
                ));
            }

            $resolvedTableName = $currentTable->name;

            if ($currentTable->name !== $targetTable->name) {
                $operations[] = new RenameTable($currentTable->name, $targetTable->name);
                $resolvedTableName = $targetTable->name;
            }

            [$indexDrops, $remainingIndexChanges] = $this->reconcileIndexes(
                $resolvedTableName,
                $currentTable,
                $targetTable,
            );
            $operations = [
                ...$operations,
                ...$indexDrops,
                ...$this->reconcileColumns($resolvedTableName, $currentTable, $targetTable),
                ...$remainingIndexChanges,
            ];
        }

        foreach ($currentTables as $tableId => $currentTable) {
            if (isset($targetTables[$tableId])) {
                continue;
            }

            $operations[] = new DropTable($currentTable->name);
        }

        // All tables and columns are in place before adding references, including self-references and cycles.
        foreach ($targetTables as $tableId => $targetTable) {
            foreach ($targetTable->foreignKeys as $name => $foreignKey) {
                if ($foreignKey->equivalentTo($currentTables[$tableId]->foreignKeys[$name] ?? null)) {
                    continue;
                }

                $referenced = $targetTables[$foreignKey->table];
                $operations[] = new AddForeignKey(
                    $targetTable->name,
                    $name,
                    $referenced->name,
                    $foreignKey->physicalColumns($targetTable, $referenced),
                    $foreignKey->timing,
                );
            }
        }

        return [...$operations, ...$this->reconcileFunctions($current, $target)];
    }

    /** @return list<CreateFunction> */
    private function reconcileFunctions(SchemaDefinition $current, SchemaDefinition $target): array
    {
        $previous = array_column($current->functions, null, 'id');
        $operations = [];
        foreach ($target->functions as $function) {
            $existing = $previous[$function->id] ?? null;
            unset($previous[$function->id]);
            if (null !== $existing && !$function->hasSameSignature($existing)) {
                throw new \LogicException(sprintf(
                    'Changing the signature of function "%s" requires a manual migration.',
                    $function->id,
                ));
            }
            if (null === $existing || !$function->equivalentTo($existing)) {
                $operations[] = new CreateFunction($function, replace: null !== $existing);
            }
        }
        if ([] !== $previous) {
            $removed = implode(', ', array_keys($previous));
            throw new \LogicException(sprintf('Removing functions requires a manual migration: %s.', $removed));
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

            if (null === $currentColumn) {
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

            if (!$currentColumn->equivalentTo($targetColumn)) {
                $operations[] = new AlterColumn(
                    tableName: $tableName,
                    columnName: $resolvedColumnName,
                    type: PostgresColumnType::equivalent($currentColumn->type, $targetColumn->type)
                        ? null
                        : $targetColumn->type,
                    nullable: $currentColumn->nullable !== $targetColumn->nullable ? $targetColumn->nullable : null,
                    defaultExpression: $currentColumn->defaultExpression !== $targetColumn->defaultExpression
                        ? $targetColumn->defaultExpression
                        : null,
                    dropDefault: null !== $currentColumn->defaultExpression
                    && null === $targetColumn->defaultExpression,
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
     * @return array{list<DropIndex>, list<AddIndex|RenameIndex>}
     */
    private function reconcileIndexes(string $tableName, TableDefinition $current, TableDefinition $target): array
    {
        $drops = [];
        $changes = [];

        $currentIndexes = $current->indexesById();
        $targetIndexes = $target->indexesById();

        foreach ($targetIndexes as $indexId => $targetIndex) {
            $currentIndex = $currentIndexes[$indexId] ?? null;

            if (null === $currentIndex) {
                $changes[] = new AddIndex($tableName, $targetIndex);
            } elseif (!$currentIndex->equivalentTo($targetIndex)) {
                $drops[] = new DropIndex($tableName, $currentIndex->name);
                $changes[] = new AddIndex($tableName, $targetIndex);
            } elseif ($currentIndex->name !== $targetIndex->name) {
                $changes[] = new RenameIndex($tableName, $currentIndex->name, $targetIndex->name);
            }
        }

        foreach ($currentIndexes as $indexId => $currentIndex) {
            if (isset($targetIndexes[$indexId])) {
                continue;
            }

            $drops[] = new DropIndex($tableName, $currentIndex->name);
        }

        return [$drops, $changes];
    }
}
