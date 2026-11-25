<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;
use Ultra\MappedTable\Storage\PostgresRowSetSql;
use Ultra\MappedTable\Storage\RowSetStorage;
use Ultra\Schema\SchemaDefinition;

/** Binds tables to one storage resource and applies an entire mapped-table flush. */
abstract class MappedTableAdapter
{
    /** @var array<string, RowSetStorage> */
    private array $tables = [];

    /**
     * Compile a selected schema table once and return a factory for fresh row buffers.
     *
     * @param ?list<string> $columns Exposed column IDs. The primary key is always included.
     * @param ?string $owner Foreign key ID for a mapped parent.
     * @param list<string> $ownerColumns Collection key columns without a mapped parent.
     * @param array<array-key, mixed> $codecs ColumnCodec instances keyed by SQL type.
     * @return \Closure(): MappedRowSet
     */
    final public function compileRowSet(
        SchemaDefinition $schema,
        string $table,
        ?array $columns = null,
        ?string $owner = null,
        array $ownerColumns = [],
        array $codecs = [],
    ): \Closure {
        $schema->validateForeignKeys();
        $registered = [];
        foreach ($codecs as $sqlType => $codec) {
            if (!$codec instanceof ColumnCodec || !is_string($sqlType)) {
                throw new \InvalidArgumentException('Register codec instances keyed by SQL type.');
            }
            $registered[ColumnCodecs::registrationKey($sqlType, $registered)] = $codec;
        }
        $mapping = MappedRowSetCompiler::compile(
            $schema->tablesById(),
            $table,
            $columns,
            $owner,
            $ownerColumns,
            $registered,
        );

        $adapter = $this;
        if ($adapter instanceof PostgresMappedTableAdapter) {
            $sql = PostgresRowSetSql::compile($mapping);

            return static fn(): MappedRowSet => $adapter->createCompiled($mapping, $sql);
        }

        return fn(): MappedRowSet => $this->createDefined($mapping);
    }

    /** @internal Bind metadata produced from Schema. */
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
     * @param non-empty-array<string, MappedKey> $keys
     * @param list<string> $columns
     * @return array<string, array<string, mixed>>
     */
    final public function rowsByKeys(string $table, array $keys, array $columns): array
    {
        return $this->tables[$table]->rowsByKeys($keys, $columns);
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

    final protected function bind(MappedRowSetMetadata $mapping, RowSetStorage $storage): MappedRowSet
    {
        if ($mapping->keyColumns !== $storage->keyColumns()) {
            throw new \InvalidArgumentException('A defined row set must use its declared key.');
        }
        $table = $mapping->table;
        // Fresh buffers may reuse an equivalent immutable table configuration.
        // @mago-expect linter:identity-comparison
        if (isset($this->tables[$table]) && $this->tables[$table] != $storage) {
            throw new \LogicException(sprintf(
                'Mapped table "%s" is already bound with a different configuration.',
                $table,
            ));
        }
        $this->tables[$table] ??= $storage;

        return new MappedRowSet($this, $mapping);
    }
}
