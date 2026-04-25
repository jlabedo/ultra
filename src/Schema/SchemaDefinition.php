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
