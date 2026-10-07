<?php

declare(strict_types=1);

namespace Ultra\Schema;

/**
 * @phpstan-type TableMap array<string, TableDefinition>
 */
final readonly class SchemaDefinition
{
    /**
     * @param list<TableDefinition> $tables
     */
    public function __construct(
        public array $tables,
    ) {
        $tableIds = [];
        $tableNames = [];

        foreach ($this->tables as $table) {
            if (isset($tableIds[$table->id])) {
                throw new \InvalidArgumentException(sprintf('Duplicate table id "%s".', $table->id));
            }

            if (isset($tableNames[$table->name])) {
                throw new \InvalidArgumentException(sprintf('Duplicate table name "%s".', $table->name));
            }

            $tableIds[$table->id] = true;
            $tableNames[$table->name] = true;
        }
    }

    public static function merge(self ...$schemas): self
    {
        $tables = [];

        foreach ($schemas as $schema) {
            foreach ($schema->tables as $table) {
                $tables[$table->id] = isset($tables[$table->id])
                    ? self::mergeTable($tables[$table->id], $table)
                    : $table;
            }
        }

        return new self(array_values($tables));
    }

    /**
     * @return TableMap
     */
    public function tablesById(): array
    {
        $tables = [];

        foreach ($this->tables as $table) {
            $tables[$table->id] = $table;
        }

        return $tables;
    }

    public function mergedWith(self $schema): self
    {
        return self::merge($this, $schema);
    }

    private static function mergeTable(TableDefinition $left, TableDefinition $right): TableDefinition
    {
        $name = self::mergeName(
            'table',
            $left->id,
            $left->name,
            $left->hasExplicitName,
            $right->name,
            $right->hasExplicitName,
        );
        $columns = self::mergeColumns($left, $right);
        $indexes = self::mergeIndexes($left, $right);

        return new TableDefinition(
            id: $left->id,
            name: $name,
            columns: $columns,
            indexes: $indexes,
            hasExplicitName: $left->hasExplicitName || $right->hasExplicitName,
        );
    }

    /**
     * @return list<ColumnDefinition>
     */
    private static function mergeColumns(TableDefinition $left, TableDefinition $right): array
    {
        $columns = [];

        foreach ([...$left->columns, ...$right->columns] as $column) {
            if (!isset($columns[$column->id])) {
                $columns[$column->id] = $column;

                continue;
            }

            $existing = $columns[$column->id];

            if (!$existing->equivalentTo($column)) {
                throw new \InvalidArgumentException(sprintf(
                    'Conflicting column definition for "%s" in table "%s".',
                    $column->id,
                    $left->id,
                ));
            }

            $columns[$column->id] = new ColumnDefinition(
                id: $column->id,
                name: self::mergeName(
                    'column',
                    $column->id,
                    $existing->name,
                    $existing->hasExplicitName,
                    $column->name,
                    $column->hasExplicitName,
                ),
                type: $column->type,
                nullable: $column->nullable,
                defaultExpression: $column->defaultExpression,
                constraints: $column->constraints,
                hasExplicitName: $existing->hasExplicitName || $column->hasExplicitName,
            );
        }

        return array_values($columns);
    }

    /**
     * @return list<IndexDefinition>
     */
    private static function mergeIndexes(TableDefinition $left, TableDefinition $right): array
    {
        $indexes = [];

        foreach ([...$left->indexes, ...$right->indexes] as $index) {
            if (!isset($indexes[$index->id])) {
                $indexes[$index->id] = $index;

                continue;
            }

            $existing = $indexes[$index->id];

            if (!$existing->equivalentTo($index)) {
                throw new \InvalidArgumentException(sprintf(
                    'Conflicting index definition for "%s" in table "%s".',
                    $index->id,
                    $left->id,
                ));
            }

            $indexes[$index->id] = new IndexDefinition(
                id: $index->id,
                name: self::mergeName(
                    'index',
                    $index->id,
                    $existing->name,
                    $existing->hasExplicitName,
                    $index->name,
                    $index->hasExplicitName,
                ),
                columns: $index->columns,
                unique: $index->unique,
                rawSql: $index->rawSql,
                hasExplicitName: $existing->hasExplicitName || $index->hasExplicitName,
            );
        }

        return array_values($indexes);
    }

    private static function mergeName(
        string $objectType,
        string $id,
        string $leftName,
        bool $leftNameIsExplicit,
        string $rightName,
        bool $rightNameIsExplicit,
    ): string {
        if ($leftNameIsExplicit && $rightNameIsExplicit && $leftName !== $rightName) {
            throw new \InvalidArgumentException(sprintf(
                'Conflicting %s name for "%s": "%s" and "%s".',
                $objectType,
                $id,
                $leftName,
                $rightName,
            ));
        }

        if ($leftNameIsExplicit) {
            return $leftName;
        }

        if ($rightNameIsExplicit) {
            return $rightName;
        }

        return $leftName;
    }
}
