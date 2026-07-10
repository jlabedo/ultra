<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class InMemoryMappedRowSetAdapterFactory implements MappedRowSetAdapterFactory
{
    public function __construct(
        private InMemoryRowDatabase $database,
    ) {}

    public function create(string $tableName, array $keyColumns = ['id']): MappedRowSetAdapter
    {
        return new InMemoryMappedRowSetAdapter($this->database, $tableName, $keyColumns);
    }

    public function createScoped(string $tableName, array $keyColumns, MappedKey $scope): MappedRowSetAdapter
    {
        return new InMemoryMappedRowSetAdapter($this->database, $tableName, $keyColumns, $scope);
    }
}
