<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;
use Ultra\MappedTable\Storage\PostgresRowSetSql;
use Ultra\MappedTable\Storage\PostgresRowSetStorage;

final class PostgresMappedTableAdapter extends MappedTableAdapter
{
    /** @param array<string, MappedTablePolicy> $tablePolicies */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $tablePolicies = [],
    ) {}

    public function assertActiveTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Mapped table flush requires an active transaction.');
        }
    }

    public function acquireGuardLock(string $namespace, string $key, GuardLockMode $mode): void
    {
        $sql = match ($mode) {
            GuardLockMode::SHARED => 'SELECT pg_advisory_xact_lock_shared(hashtext(?), hashtext(?))',
            GuardLockMode::EXCLUSIVE => 'SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))',
        };
        $this->connection->executeQuery($sql, [$namespace, $key]);
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
        PostgresRowSetSql::validateColumnTypes($mapping, $columnTypes);

        return $this->bindDefined($mapping, $columnTypes, null);
    }

    /** @internal */
    public function createCompiled(MappedRowSetMetadata $mapping, PostgresRowSetSql $sql): MappedRowSet
    {
        return $this->bindDefined($mapping, $sql->columnTypes, $sql);
    }

    /** @param array<string, PostgresMappedColumnType> $columnTypes */
    private function bindDefined(
        MappedRowSetMetadata $mapping,
        array $columnTypes,
        ?PostgresRowSetSql $sql,
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
