<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class InMemoryMappedRowSetAdapterFactory implements MappedRowSetAdapterFactory
{
    /** @param array<string, MappedTablePolicy> $tablePolicies Policies shared by scoped and unscoped adapters. */
    public function __construct(
        private InMemoryRowDatabase $database,
        private array $tablePolicies = [],
    ) {}

    public function create(string $tableName, array $keyColumns = ['id']): MappedRowSetAdapter
    {
        return new InMemoryMappedRowSetAdapter(
            $this->database,
            $tableName,
            $keyColumns,
            policy: $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
        );
    }

    public function createScoped(string $tableName, array $keyColumns, MappedKey $scope): MappedRowSetAdapter
    {
        return new InMemoryMappedRowSetAdapter(
            $this->database,
            $tableName,
            $keyColumns,
            $scope,
            $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
        );
    }
}
