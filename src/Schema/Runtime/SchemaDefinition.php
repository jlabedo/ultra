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
     * @param list<string> $requiredExtensions
     * @param list<PostgresFunctionDefinition> $functions
     */
    public function __construct(
        public array $tables,
        public array $requiredExtensions = [],
        public array $functions = [],
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

        $functionIds = [];
        $signatures = [];
        foreach ($this->functions as $function) {
            $signature = $function->name . '(' . implode(', ', array_values($function->arguments)) . ')';
            if (isset($functionIds[$function->id]) || isset($signatures[$signature])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate function "%s" with signature "%s".',
                    $function->id,
                    $signature,
                ));
            }
            $functionIds[$function->id] = true;
            $signatures[$signature] = true;
        }
    }

    public static function merge(self ...$schemas): self
    {
        $tables = [];
        $extensions = [];
        $functions = [];

        foreach ($schemas as $schema) {
            foreach ($schema->requiredExtensions as $extension) {
                $extensions[$extension] = $extension;
            }
            foreach ($schema->functions as $function) {
                if (isset($functions[$function->id]) && !$functions[$function->id]->equivalentTo($function)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Conflicting function definition for "%s".',
                        $function->id,
                    ));
                }
                $functions[$function->id] = $function;
            }
            foreach ($schema->tables as $table) {
                $tables[$table->id] = isset($tables[$table->id])
                    ? self::mergeTable($tables[$table->id], $table)
                    : $table;
            }
        }

        return new self(array_values($tables), array_values($extensions), array_values($functions));
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

    /** Validate references once all schema fragments have been assembled. */
    public function validateForeignKeys(): void
    {
        $tables = $this->tablesById();
        foreach ($this->tables as $table) {
            foreach ($table->foreignKeys as $name => $foreignKey) {
                $referenced = $tables[$foreignKey->table] ?? null;
                if (null === $referenced || array_values($foreignKey->columns) !== $referenced->primaryKey) {
                    throw new \InvalidArgumentException(sprintf(
                        'Foreign key "%s" of table "%s" must reference the complete primary key of "%s" in key order.',
                        $name,
                        $table->id,
                        $foreignKey->table,
                    ));
                }
            }
        }
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
        if ([] !== $left->primaryKey && [] !== $right->primaryKey && $left->primaryKey !== $right->primaryKey) {
            throw new \InvalidArgumentException(sprintf('Conflicting primary key for table "%s".', $left->id));
        }
        $foreignKeys = $left->foreignKeys;
        foreach ($right->foreignKeys as $key => $foreignKey) {
            $existing = $foreignKeys[$key] ?? null;
            if (null !== $existing && !$existing->equivalentTo($foreignKey)) {
                throw new \InvalidArgumentException(sprintf(
                    'Conflicting foreign key "%s" for table "%s".',
                    $key,
                    $left->id,
                ));
            }
            $foreignKeys[$key] = $foreignKey;
        }
        $checks = $left->checks;
        foreach ($right->checks as $key => $expression) {
            if (isset($checks[$key]) && $checks[$key] !== $expression) {
                throw new \InvalidArgumentException(sprintf('Conflicting check "%s" for table "%s".', $key, $left->id));
            }
            $checks[$key] = $expression;
        }
        if (
            null !== $left->rowLevelSecurity
            && null !== $right->rowLevelSecurity
            && $left->rowLevelSecurity !== $right->rowLevelSecurity
        ) {
            throw new \InvalidArgumentException(sprintf('Conflicting row-level security for table "%s".', $left->id));
        }
        $policies = $left->policies;
        foreach ($right->policies as $key => $policy) {
            if (isset($policies[$key]) && !$policy->equivalentTo($policies[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'Conflicting policy "%s" for table "%s".',
                    $key,
                    $left->id,
                ));
            }
            $policies[$key] = $policy;
        }

        return new TableDefinition(
            id: $left->id,
            name: $name,
            columns: $columns,
            indexes: $indexes,
            hasExplicitName: $left->hasExplicitName || $right->hasExplicitName,
            primaryKey: [] === $left->primaryKey ? $right->primaryKey : $left->primaryKey,
            foreignKeys: $foreignKeys,
            checks: $checks,
            rowLevelSecurity: $left->rowLevelSecurity ?? $right->rowLevelSecurity,
            policies: $policies,
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
                method: $index->method,
                predicate: $index->predicate,
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
