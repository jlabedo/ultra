<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Storage\InMemoryRowSetStorage;

final class InMemoryMappedTableAdapter extends MappedTableAdapter
{
    /** @param array<string, MappedTablePolicy> $tablePolicies */
    public function __construct(
        private readonly InMemoryRowDatabase $database,
        private readonly array $tablePolicies = [],
    ) {}

    public function assertActiveTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('Mapped table flush requires an active transaction.');
        }
    }

    public function acquireGuardLock(string $namespace, string $key, GuardLockMode $mode): void {}

    public function create(string $tableName, array $keyColumns = ['id']): MappedRowSet
    {
        return $this->bind(
            $tableName,
            new InMemoryRowSetStorage(
                $this->database,
                $tableName,
                $keyColumns,
                policy: $this->tablePolicies[$tableName] ?? new MappedTablePolicy(),
            ),
        );
    }

    /** @internal */
    public function createDefined(MappedRowSetMetadata $mapping): MappedRowSet
    {
        return $this->bind(
            $mapping->table,
            new InMemoryRowSetStorage(
                $this->database,
                $mapping->table,
                $mapping,
                policy: $this->tablePolicies[$mapping->table] ?? new MappedTablePolicy(),
            ),
            $mapping,
        );
    }
}
