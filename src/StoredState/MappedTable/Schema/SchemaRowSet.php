<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Schema;

use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\Schema\SchemaDefinition;

/** Derives an explicit row-set definition from a Schema table. */
final class SchemaRowSet
{
    /**
     * @param ?list<string> $columns
     * @param list<string> $ownerColumns
     */
    public static function fromTable(
        SchemaDefinition $schema,
        string $tableId,
        ?array $columns = null,
        ?string $owner = null,
        array $ownerColumns = [],
    ): MappedRowSetDefinition {
        $schema->validateForeignKeys();
        $tables = $schema->tablesById();
        if ('' === $tableId || is_numeric($tableId)) {
            throw new \InvalidArgumentException('A mapped schema table needs a non-empty, non-numeric ID.');
        }
        $table = $tables[$tableId] ?? throw new \LogicException(sprintf('Unknown schema table "%s".', $tableId));
        if ([] === $table->primaryKey) {
            throw new \LogicException(sprintf('Mapped schema table "%s" requires a primary key.', $tableId));
        }

        $declared = $table->columnsById();
        $exposed = null === $columns ? array_keys($declared) : array_unique([...$table->primaryKey, ...$columns]);
        $mapping = [];
        $nullable = [];
        foreach ($exposed as $id) {
            $column = $declared[$id] ?? throw new \LogicException(sprintf(
                'Unknown column "%s" on schema table "%s".',
                $id,
                $tableId,
            ));
            $mapping[$column->name] = $column->type;
            if ($column->nullable) {
                $nullable[] = $column->name;
            }
        }

        $keys = array_map(static fn(string $id): string => $declared[$id]->name, $table->primaryKey);
        $scope = array_map(
            static fn(string $id): string => (
                $declared[$id] ?? throw new \LogicException(sprintf(
                    'Unknown owner column "%s" on schema table "%s".',
                    $id,
                    $tableId,
                ))
            )->name,
            $ownerColumns,
        );

        $dependency = null;
        if (null !== $owner) {
            $foreignKey = $table->foreignKeys[$owner] ?? throw new \LogicException(sprintf(
                'Unknown owner foreign key "%s" on schema table "%s".',
                $owner,
                $tableId,
            ));
            $dependency = new MappedRowSetOwner(
                $foreignKey->table,
                $foreignKey->physicalColumns($table, $tables[$foreignKey->table]),
            );
        }

        return new MappedRowSetDefinition($table->name, $mapping, $keys, $nullable, $scope, $dependency);
    }
}
