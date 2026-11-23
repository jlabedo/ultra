<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;
use Ultra\MappedTable\Storage\RowSetStorage;

/** Binds tables to one storage resource and applies an entire mapped-table flush. */
abstract class MappedTableAdapter
{
    /** @var array<string, RowSetStorage> */
    private array $tables = [];

    /** @param list<string> $keyColumns */
    abstract public function create(string $tableName, array $keyColumns = ['id']): MappedRowSet;

    abstract public function createDefined(MappedRowSetMetadata $mapping): MappedRowSet;

    /** @internal Require a transaction on the storage used by this adapter. */
    abstract public function assertActiveTransaction(): void;

    /** @internal Called after assertActiveTransaction(), before replaying queries and applying row plans. */
    abstract public function acquireGuardLock(string $namespace, string $key, GuardLockMode $mode): void;

    /**
     * @internal
     * @param list<string> $columns
     * @return array<string, mixed>|false
     */
    final public function row(string $table, MappedKey $key, array $columns): array|false
    {
        return $this->tables[$table]->row($key, $columns);
    }

    /**
     * @internal
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    final public function rows(string $table, array $columns, ?MappedKey $owner): array
    {
        return $this->tables[$table]->rows($columns, $owner);
    }

    /**
     * @internal Called by the unit of work after it verifies the active transaction.
     * @param array<string, RowSetUpdatePlan> $plans Plans by physical table, parents before children.
     */
    public function flush(array $plans): void
    {
        foreach ($plans as $table => $plan) {
            $this->tables[$table]->check($plan);
        }

        foreach (array_reverse($plans, preserve_keys: true) as $table => $plan) {
            $storage = $this->tables[$table];
            $storage->writeRows(array_values(array_filter(
                $plan->rowUpdates,
                static fn(RowUpdatePlan $row): bool => RowUpdatePlan::MODE_DELETE === $row->mode,
            )));
            $storage->deleteExcludedRows($plan);
        }

        foreach ($plans as $table => $plan) {
            $this->tables[$table]->writeRows(array_values(array_filter(
                $plan->rowUpdates,
                static fn(RowUpdatePlan $row): bool => $row->writes() && RowUpdatePlan::MODE_DELETE !== $row->mode,
            )));
        }
    }

    final protected function bind(
        string $table,
        RowSetStorage $storage,
        ?MappedRowSetMetadata $mapping = null,
    ): MappedRowSet {
        if (null !== $mapping && $mapping->keyColumns !== $storage->keyColumns()) {
            throw new \InvalidArgumentException('A defined row set must use its declared key.');
        }
        // Fresh buffers may reuse an equivalent immutable table configuration.
        // @mago-expect linter:identity-comparison
        if (isset($this->tables[$table]) && $this->tables[$table] != $storage) {
            throw new \LogicException(sprintf(
                'Mapped table "%s" is already bound with a different configuration.',
                $table,
            ));
        }
        $this->tables[$table] ??= $storage;

        return new MappedRowSet($this, $table, $storage->keyColumns(), $mapping);
    }
}
