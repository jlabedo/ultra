<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class InMemoryMappedRowSetAdapterFactory implements MappedRowSetAdapterFactory
{
    public function __construct(
        private InMemoryRowDatabase $database,
    ) {}

    public function create(string $tableName, string $idColumn = 'id'): MappedRowSetAdapter
    {
        return new InMemoryMappedRowSetAdapter($this->database, $tableName, $idColumn);
    }
}
