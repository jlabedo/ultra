<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final readonly class PostgresMappedRowSetAdapterFactory implements MappedRowSetAdapterFactory
{
    /**
     * @param array<string, array<string, PostgresMappedColumnType>> $columnTypesByTable
     */
    public function __construct(
        private Connection $connection,
        private array $columnTypesByTable = [],
    ) {}

    public function create(string $tableName, array $keyColumns = ['id']): MappedRowSetAdapter
    {
        return new PostgresMappedRowSetAdapter(
            $this->connection,
            $tableName,
            $keyColumns,
            $this->columnTypesByTable[$tableName] ?? [],
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
        );
    }
}
