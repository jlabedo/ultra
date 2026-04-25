<?php

declare(strict_types=1);

namespace Ultra\Schema;

/**
 * @phpstan-type ColumnMap array<string, ColumnDefinition>
 * @phpstan-type IndexMap array<string, IndexDefinition>
 */
final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     * @param list<IndexDefinition> $indexes
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $columns,
        public array $indexes = [],
    ) {
        $columnIds = [];
        $columnNames = [];

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

            $columnIds[$column->id] = true;
            $columnNames[$column->name] = true;
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
