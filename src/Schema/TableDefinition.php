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
