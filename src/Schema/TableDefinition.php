<?php

declare(strict_types=1);

namespace Ultra\Schema;

/**
 * @phpstan-type ColumnMap array<string, ColumnDefinition>
 */
final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $columns,
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
}
