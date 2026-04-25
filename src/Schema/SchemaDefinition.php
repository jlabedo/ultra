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
}
