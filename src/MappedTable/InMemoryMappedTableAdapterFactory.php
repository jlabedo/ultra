<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class InMemoryMappedTableAdapterFactory implements MappedTableAdapterFactory
{
    public function __construct(
        private InMemoryRowDatabase $database,
    ) {}

    public function create(string $tableName, string $idColumn = 'id'): MappedTableAdapter
    {
        return new InMemoryMappedTableAdapter($this->database, $tableName, $idColumn);
    }
}
