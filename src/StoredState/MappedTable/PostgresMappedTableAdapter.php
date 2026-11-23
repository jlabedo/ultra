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

    /** @param array<string, PostgresMappedColumnType> $columnTypes */
    public function createDefined(MappedRowSetMetadata $mapping, array $columnTypes = []): MappedRowSet
    {
        return $this->createCompiled($mapping, PostgresRowSetSql::compile($mapping, $columnTypes));
    }

    /** @internal */
    public function createCompiled(MappedRowSetMetadata $mapping, PostgresRowSetSql $sql): MappedRowSet
    {
        return $this->bind(
            $mapping,
            new PostgresRowSetStorage(
                $this->connection,
                $mapping->table,
                $mapping->keyColumns,
                $sql,
                policy: $this->tablePolicies[$mapping->table] ?? new MappedTablePolicy(),
            ),
        );
    }
}
