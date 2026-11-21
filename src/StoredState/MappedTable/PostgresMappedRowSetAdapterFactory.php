<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final readonly class PostgresMappedRowSetAdapterFactory implements MappedRowSetAdapterFactory
{
    /**
     * @param array<string, array<string, PostgresMappedColumnType>> $columnTypesByTable
     * @param array<string, MappedTablePolicy> $tablePolicies Policies shared by scoped and unscoped adapters.
     */
    public function __construct(
        private Connection $connection,
        private array $columnTypesByTable = [],
        private array $tablePolicies = [],
    ) {}

    /**
     * @param list<string> $keyColumns
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function create(string $tableName, array $keyColumns = ['id'], array $columnTypes = []): MappedRowSetAdapter
    {
        return new PostgresMappedRowSetAdapter(
            $this->connection,
            $tableName,
            $keyColumns,
            [...($this->columnTypesByTable[$tableName] ?? []), ...$columnTypes],
            policy: $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
        );
    }

    public function createScoped(string $tableName, array $keyColumns, MappedKey $scope): MappedRowSetAdapter
    {
        return new PostgresMappedRowSetAdapter(
            $this->connection,
            $tableName,
            $keyColumns,
            $this->columnTypesByTable[$tableName] ?? [],
            $scope,
            $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
        );
    }
}
