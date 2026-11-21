<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final class PostgresMappedTableAdapter extends MappedTableAdapter
{
    /** @param array<string, MappedTablePolicy> $tablePolicies */
    public function __construct(
        private readonly Connection $connection,
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
                $columnTypes,
                policy: $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
            ),
        );
    }

    /** @param array<string, PostgresMappedColumnType> $columnTypes */
    public function createDefined(MappedRowSetMetadata $mapping, array $columnTypes = []): MappedRowSet
    {
        PostgresMappedRowSetSql::validateColumnTypes($mapping, $columnTypes);

        return $this->bindDefined($mapping, $columnTypes, null);
    }

    /** @internal */
    public function createCompiled(MappedRowSetMetadata $mapping, PostgresMappedRowSetSql $sql): MappedRowSet
    {
        return $this->bindDefined($mapping, $sql->columnTypes, $sql);
    }

    /** @param array<string, PostgresMappedColumnType> $columnTypes */
    private function bindDefined(
        MappedRowSetMetadata $mapping,
        array $columnTypes,
        ?PostgresMappedRowSetSql $sql,
    ): MappedRowSet {
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
