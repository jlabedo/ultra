<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class InMemoryMappedRowSetAdapterFactory implements MappedRowSetAdapterFactory
{
    /** @param array<string, MappedTablePolicy> $tablePolicies */
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

    /** @internal */
    public function createDefined(MappedRowSetMetadata $mapping): MappedRowSetAdapter
    {
        return new InMemoryMappedRowSetAdapter(
            $this->database,
            $mapping->table,
            $mapping,
            policy: $this->tablePolicies[$mapping->table] ?? new MappedTablePolicy(),
        );
    }
}
