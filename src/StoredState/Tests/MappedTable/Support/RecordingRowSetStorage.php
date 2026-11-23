<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Ultra\MappedTable\GuardLockMode;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Storage\RowSetStorage;

final class RecordingRowSetStorage implements RowSetStorage
{
    public int $reads = 0;

    /** @var list<list<string>> */
    public array $rowProjections = [];

    /** @var list<RowSetUpdatePlan> */
    public array $batches = [];

    public function keyColumns(): array
    {
        return ['id'];
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        ++$this->reads;
        $this->rowProjections[] = $columns;
        return false;
    }

    public function rows(array $columns = [], ?MappedKey $owner = null): array
    {
        ++$this->reads;
        return [];
    }

    /** @return array{MappedRowSet, MappedTableUnitOfWork} */
    public function assemble(): array
    {
        $adapter = new class($this) extends MappedTableAdapter {
            public function __construct(
                private readonly RecordingRowSetStorage $storage,
            ) {}

            public function create(string $tableName, array $keyColumns = ['id']): MappedRowSet
            {
                return $this->bind($tableName, $this->storage);
            }

            public function createDefined(MappedRowSetMetadata $mapping): MappedRowSet
            {
                return $this->bind($mapping->table, $this->storage, $mapping);
            }

            public function assertActiveTransaction(): void {}

            public function acquireGuardLock(string $namespace, string $key, GuardLockMode $mode): void {}
        };
        $rows = $adapter->create('rows');

        return [$rows, new MappedTableUnitOfWork(['rows' => $rows], $adapter)];
    }

    public function check(RowSetUpdatePlan $plan): void
    {
        $this->batches[] = $plan;
    }

    public function writeRows(array $writes): void {}

    public function deleteExcludedRows(RowSetUpdatePlan $plan): void {}
}
