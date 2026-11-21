<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final class PostgresMappedTableAdapter extends MappedTableAdapter
{
    /**
     * @param array<string, array<string, PostgresMappedColumnType>> $columnTypesByTable
     * @param array<string, MappedTablePolicy> $tablePolicies
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $columnTypesByTable = [],
        private readonly array $tablePolicies = [],
    ) {}

    public function activeTransactionResource(): object
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Mapped table flush requires an active transaction.');
        }

        return $this->connection;
    }

    /**
     * @param list<string> $keyColumns
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function create(string $tableName, array $keyColumns = ['id'], array $columnTypes = []): MappedRowSet
    {
        return $this->bind(
            $tableName,
            new PostgresRowSetStorage(
                $this->connection,
                $tableName,
                $keyColumns,
                [...($this->columnTypesByTable[$tableName] ?? []), ...$columnTypes],
                policy: $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
            ),
        );
    }

    /**
     * @internal
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function createDefined(
        MappedRowSetMetadata $mapping,
        array $columnTypes = [],
        ?PostgresMappedRowSetSql $sql = null,
        bool $needsRuntimeDefaults = true,
    ): MappedRowSet {
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
            if (null !== $sql) {
                $sql = PostgresMappedRowSetSql::compile($mapping, $defaults, $sql);
            }
        }

        return $this->bind(
            $mapping->table,
            new PostgresRowSetStorage(
                $this->connection,
                $mapping->table,
                $mapping->keyColumns,
                $columnTypes,
                policy: $this->tablePolicies[$mapping->table] ?? new MappedTablePolicy(),
                sql: $sql,
            ),
            $mapping,
        );
    }
}
