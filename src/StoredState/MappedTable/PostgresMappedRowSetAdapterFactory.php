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

    /**
     * @internal
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function createDefined(
        MappedRowSetMetadata $mapping,
        array $columnTypes,
        PostgresMappedRowSetSql $sql,
        bool $needsRuntimeDefaults,
    ): MappedRowSetAdapter {
        $defaults = !$needsRuntimeDefaults
            ? []
            : array_diff_key(
                array_intersect_key($this->columnTypesByTable[$mapping->table] ?? [], $mapping->columns),
                $columnTypes,
            );
        if ([] !== $defaults) {
            foreach ($defaults as $column => $type) {
                $type->assertSupports($column, $mapping->column($column));
            }
            $columnTypes += $defaults;
            $sql = PostgresMappedRowSetSql::compile($mapping, $defaults, $sql);
        }

        return new PostgresMappedRowSetAdapter(
            $this->connection,
            $mapping->table,
            $mapping->keyColumns,
            $columnTypes,
            policy: $this->tablePolicies[$mapping->table] ?? new MappedTablePolicy(),
            sql: $sql,
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
