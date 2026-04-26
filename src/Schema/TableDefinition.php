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
     */
    public function __construct(
        public string $id,
        ?string $name = null,
        public array $columns = [],
        public array $indexes = [],
        ?bool $hasExplicitName = null,
    ) {
        $this->name = $name ?? $this->id;
        $this->hasExplicitName = $hasExplicitName ?? $name !== null;

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

            $columnIds[$column->id] = true;
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
