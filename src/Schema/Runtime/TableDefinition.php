<?php

declare(strict_types=1);

namespace Ultra\Schema;

/**
 * @phpstan-type ColumnMap array<string, ColumnDefinition>
 * @phpstan-type IndexMap array<string, IndexDefinition>
 */
final readonly class TableDefinition
{
    public string $name;

    public bool $hasExplicitName;

    /**
     * @param list<ColumnDefinition> $columns
     * @param list<IndexDefinition> $indexes
     * @param list<string> $primaryKey Column IDs in key order.
     * @param array<string, ForeignKeyDefinition> $foreignKeys By constraint name.
     * @param array<string, string> $checks SQL expressions by constraint name.
     */
    public function __construct(
        public string $id,
        ?string $name = null,
        public array $columns = [],
        public array $indexes = [],
        ?bool $hasExplicitName = null,
        public array $primaryKey = [],
        public array $foreignKeys = [],
        public array $checks = [],
    ) {
        $this->name = $name ?? $this->id;
        $this->hasExplicitName = $hasExplicitName ?? null !== $name;

        $columnIds = [];
        $columnNames = [];
        $indexIds = [];
        $indexNames = [];

        foreach ($this->columns as $column) {
            if (isset($columnIds[$column->id])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate column id "%s" in table "%s".',
                    $column->id,
                    $this->name,
                ));
            }

            if (isset($columnNames[$column->name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate column name "%s" in table "%s".',
                    $column->name,
                    $this->name,
                ));
            }

            $columnIds[$column->id] = $column;
            $columnNames[$column->name] = true;
        }

        foreach ($this->indexes as $index) {
            if (isset($indexIds[$index->id])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate index id "%s" in table "%s".',
                    $index->id,
                    $this->name,
                ));
            }

            if (isset($indexNames[$index->name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate index name "%s" in table "%s".',
                    $index->name,
                    $this->name,
                ));
            }

            $indexIds[$index->id] = true;
            $indexNames[$index->name] = true;
        }

        $keyColumns = [];
        foreach ($this->primaryKey as $column) {
            if (!isset($columnIds[$column]) || $columnIds[$column]->nullable || isset($keyColumns[$column])) {
                throw new \InvalidArgumentException(sprintf(
                    'Primary key of table "%s" requires distinct, declared, non-nullable columns. Invalid column "%s".',
                    $this->id,
                    $column,
                ));
            }
            $keyColumns[$column] = true;
        }

        foreach ($this->foreignKeys as $name => $foreignKey) {
            if (
                '' === $name
                || [] === $foreignKey->columns
                || [] !== array_diff_key($foreignKey->columns, $columnIds)
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'Foreign key "%s" of table "%s" needs a name and declared local columns.',
                    $name,
                    $this->id,
                ));
            }
        }
        foreach ($this->checks as $name => $expression) {
            if ('' === trim($name) || '' === trim($expression) || isset($this->foreignKeys[$name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Check constraint "%s" of table "%s" needs a distinct name and an expression.',
                    $name,
                    $this->id,
                ));
            }
        }
    }

    /**
     * @return ColumnMap
     */
    public function columnsById(): array
    {
        $columns = [];

        foreach ($this->columns as $column) {
            $columns[$column->id] = $column;
        }

        return $columns;
    }

    /**
     * @return IndexMap
     */
    public function indexesById(): array
    {
        $indexes = [];

        foreach ($this->indexes as $index) {
            $indexes[$index->id] = $index;
        }

        return $indexes;
    }
}
