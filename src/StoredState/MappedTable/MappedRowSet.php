<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Logical row-set unit of work that buffers conditional row changes and flushes them through an adapter.
 */
final class MappedRowSet implements UnitOfWork
{
    /** @var array<string, BufferedRowUpdate> */
    private array $updates = [];

    private bool $coordinated = false;

    private int $generation = 0;

    /** @var array<string, BufferedRowMembership> */
    private array $memberships = [];

    public function __construct(
        private readonly MappedRowSetAdapter $adapter,
        private readonly ?MappedRowSetMetadata $mapping = null,
        private readonly bool $requiresCoordinator = false,
    ) {
        if (null !== $mapping && $mapping->keyColumns !== $adapter->keyColumns()) {
            throw new \InvalidArgumentException('A defined row set needs an adapter with the declared key.');
        }
    }

    /** @param int|string|MappedKey|\Stringable|array<string, int|string|\Stringable> $key */
    public function withKey(int|string|MappedKey|\Stringable|array $key): PendingRowUpdate
    {
        return new PendingRowUpdate(
            $this,
            $this->normalizeKey($key),
            $this->generation,
            $this->mapping->table ?? 'mapped row set',
            $this->mapping,
        );
    }

    /** @internal True when a buffered deletion, cancellation or reconciliation removes the row. */
    public function hasPendingDeletion(MappedKey $key): bool
    {
        $update = $this->updates[$key->fingerprint()] ?? null;

        return null !== $update && (RowUpdatePlan::MODE_DELETE === $update->mode || $update->removedByReconciliation);
    }

    /** @internal */
    public function updateForHandle(MappedKey $key, int $generation): BufferedRowUpdate
    {
        $this->assertHandleGeneration($generation);
        $fingerprint = $key->fingerprint();
        if (!isset($this->updates[$fingerprint])) {
            $update = new BufferedRowUpdate($key);
            $reconciled = $this->membershipFor($key)?->reconciled;
            $update->removedByReconciliation = null !== $reconciled && !isset($reconciled[$fingerprint]);
            $this->updates[$fingerprint] = $update;
        }

        return $this->updates[$fingerprint];
    }

    /** @param iterable<MappedRow> $rows */
    public function reconcileRows(iterable $rows): void
    {
        $this->assertWholeTableMembership();
        $this->reconcile(null, $rows);
    }

    /**
     * @param MappedKey|array<string, int|string|\Stringable> $owner
     * @param iterable<MappedRow> $rows
     */
    public function reconcileForOwner(MappedKey|array $owner, iterable $rows): void
    {
        $this->reconcile($this->normalizeOwner($owner), $rows);
    }

    /** @param iterable<MappedRow> $rows */
    private function reconcile(?MappedKey $owner, iterable $rows): void
    {
        $desiredKeys = [];
        $desiredColumns = [];
        foreach ($rows as $row) {
            $key = $this->normalizeKey($row->key);
            if (!$this->belongsTo($key, $owner)) {
                throw new \InvalidArgumentException('Mapped row key does not belong to the declared owner.');
            }

            foreach ($row->columns as $column => $value) {
                $this->mapping?->assertWritableValue($column, $value);
            }
            $fingerprint = $key->fingerprint();
            $desiredKeys[$fingerprint] = $key;
            $desiredColumns[$fingerprint] = [...($desiredColumns[$fingerprint] ?? []), ...$row->columns];
        }

        foreach ($desiredKeys as $fingerprint => $key) {
            $this->withKey($key)->upsert()->setColumns($desiredColumns[$fingerprint]);
        }

        foreach ($this->updates as $fingerprint => $update) {
            if (isset($desiredKeys[$fingerprint]) || !$this->belongsTo($update->key, $owner)) {
                continue;
            }

            // Discard superseded writes, retaining expectations on the committed row.
            $update->mode = null;
            $update->setColumns = [];
            $update->removedByReconciliation = true;
        }
        $this->membership($owner)->reconciled = $desiredKeys;
    }

    /** An immutable snapshot of the buffered row changes and expectations. */
    public function updatePlan(): RowSetUpdatePlan
    {
        $memberships = $this->memberships;
        ksort($memberships, SORT_STRING);
        $membershipPlans = [];
        $updatesByMembership = [];
        if ([] !== $memberships) {
            foreach ($this->updates as $update) {
                $updatesByMembership[$this->membershipIdForRow($update->key)][] = $update;
            }
        }
        foreach ($memberships as $id => $membership) {
            $retained = $membership->retained($updatesByMembership[$id] ?? []);
            $membershipPlans[] = new RowMembershipPlan(
                $membership->owner,
                $membership->expected,
                $membership->ignored,
                null === $retained ? null : array_values($retained),
            );
        }

        return new RowSetUpdatePlan($this->sortedPlans(), $membershipPlans);
    }

    /**
     * @internal
     * @param list<string>|null $columns
     * @return array{values: array<string, mixed>, storedValues: array<string, mixed>}|null
     */
    public function readForHandle(MappedKey $key, int $generation, ?array $columns): ?array
    {
        $this->assertHandleGeneration($generation);
        $keyOnly = [] === $columns;
        $projection = $keyOnly
            ? $this->mapping->keyColumns ?? $key->columns()
            : $this->selectedColumns($columns ?? [], includeKeys: true);
        $update = $this->updates[$key->fingerprint()] ?? null;
        if (!$this->isVisible($key, $update)) {
            return null;
        }

        $plan = $update?->toPlan();
        $stored = in_array($plan?->mode, [RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_REPLACE], true)
            ? false
            : $this->adapter->row($key, $projection);
        $written = null !== $plan && $plan->writes() ? [...$plan->setColumns, ...$key->values] : [];
        if (false === $stored && [] === $written) {
            return null;
        }

        $stored = false === $stored ? [] : $stored;
        $values = [];
        $storedValues = [];
        foreach ([] === $projection ? array_keys($stored + $written) : $projection as $column) {
            $fromStorage = array_key_exists($column, $stored);
            if ($fromStorage) {
                $this->mapping?->assertValue($column, $stored[$column]);
            }
            if (array_key_exists($column, $written)) {
                $values[$column] = $written[$column];
            } elseif ($fromStorage) {
                $values[$column] = $stored[$column];
                if (!$key->contains($column)) {
                    $storedValues[$column] = $stored[$column];
                }
            }
        }

        return ['values' => $keyOnly ? $key->values : $values, 'storedValues' => $storedValues];
    }

    /**
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    public function rows(array $columns = []): array
    {
        return $this->readRows(null, $columns);
    }

    /**
     * @param MappedKey|array<string, int|string|\Stringable> $owner
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    public function rowsForOwner(MappedKey|array $owner, array $columns = []): array
    {
        return $this->readRows($this->normalizeOwner($owner), $columns);
    }

    /**
     * Reads the complete item keys visible for this owner and observes their membership.
     *
     * @param MappedKey|array<string, int|string|\Stringable> $owner
     * @return list<MappedKey>
     */
    public function observeKeysForOwner(MappedKey|array $owner): array
    {
        $owner = $this->normalizeOwner($owner);
        $keys = array_map(
            static fn(MappedRow $row): MappedKey => $row->key,
            $this->readRows($owner, $this->adapter->keyColumns()),
        );
        $this->expectKeys($owner, $keys);

        return $keys;
    }

    /**
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    private function readRows(?MappedKey $owner, array $columns): array
    {
        $mapping = $this->mapping;
        $columns = $this->selectedColumns($columns);
        $selected = [] === $columns && null !== $mapping ? $mapping->keyColumns : $columns;
        $committed = $this->adapter->rows($selected, $owner);
        $rows = [];
        foreach ($committed as $row) {
            $fingerprint = $row->key->fingerprint();
            $update = $this->updates[$fingerprint] ?? null;
            if (!$this->isVisible($row->key, $update)) {
                continue;
            }

            if (null !== $mapping) {
                $mapping->assertKey($row->key);
                if (in_array($update?->mode, [RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_REPLACE], true)) {
                    continue;
                }
                $values = [] === $columns ? [] : $this->projectRow([...$row->columns, ...$row->key->values], $columns);
                $mapping->assertValues($values);
                $row = new MappedRow($row->key, $values);
            }
            $rows[$fingerprint] = $row;
        }

        foreach ($this->sortedPlans($owner) as $plan) {
            if (!$plan->writes()) {
                continue;
            }

            $fingerprint = $plan->key->fingerprint();
            $existing = $rows[$fingerprint] ?? null;
            $values = $this->applyPlanToRow($existing->columns ?? false, $plan);
            if (false === $values) {
                unset($rows[$fingerprint]);

                continue;
            }

            if (null !== $mapping) {
                $values = [] === $columns ? [] : [...$values, ...$plan->key->values];
            }
            $rows[$fingerprint] = new MappedRow($plan->key, $this->projectRow($values, $columns));
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /** @param list<int|string|MappedKey|\Stringable|array<string, int|string|\Stringable>> $keys */
    public function expectSameRowKeys(array $keys): void
    {
        $this->assertWholeTableMembership();
        $this->expectKeys(null, array_map($this->normalizeKey(...), $keys));
    }

    /**
     * @param MappedKey|array<string, int|string|\Stringable> $owner
     * @param list<MappedKey|array<string, int|string|\Stringable>> $keys
     */
    public function expectSameRowKeysForOwner(MappedKey|array $owner, array $keys): void
    {
        $owner = $this->normalizeOwner($owner);
        $expected = [];
        foreach ($keys as $key) {
            $key = $this->normalizeKey($key);
            if (!$key->matches($owner)) {
                throw new \InvalidArgumentException('Expected mapped row key does not belong to the declared owner.');
            }
            $expected[] = $key;
        }
        $this->expectKeys($owner, $expected);
    }

    /** @param list<MappedKey> $keys */
    private function expectKeys(?MappedKey $owner, array $keys): void
    {
        $id = $owner?->fingerprint() ?? '';
        $membership = $this->memberships[$id] ?? new BufferedRowMembership($owner);
        $updates = null === $owner
            ? array_values($this->updates)
            : array_values(array_filter(
                $this->updates,
                static fn(BufferedRowUpdate $update): bool => $update->key->matches($owner),
            ));
        $membership->expect($keys, $updates);
        $this->memberships[$id] = $membership;
    }

    /** @internal */
    public function mappingForCoordination(): MappedRowSetMetadata
    {
        return $this->mapping ?? throw new \LogicException('A coordinated row set needs a definition.');
    }

    /** @internal */
    public function adapterForCoordination(): MappedRowSetAdapter
    {
        if ($this->coordinated) {
            throw new \LogicException('This mapped row set already has a flush coordinator.');
        }
        return $this->adapter;
    }

    /** @internal */
    public function enrollInCoordinator(): void
    {
        $this->coordinated = true;
    }

    public function flush(): void
    {
        if ($this->coordinated || $this->requiresCoordinator || null !== $this->mapping?->owner) {
            throw new \LogicException('Flush this mapped row set through its coordinator.');
        }
        if (!$this->hasPendingChanges()) {
            return;
        }

        RowSetPlanFlusher::flush([$this->updatePlan()], [$this->adapter]);
        $this->clear();
    }

    public function hasPendingChanges(): bool
    {
        if ([] !== $this->memberships) {
            return true;
        }

        foreach ($this->updates as $update) {
            if (null !== $update->toPlan()) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        ++$this->generation;
        $this->updates = [];
        $this->memberships = [];
    }

    private function assertWholeTableMembership(): void
    {
        if ([] !== ($this->mapping->ownerColumns ?? [])) {
            throw new \LogicException('Row sets with owner columns require membership operations by owner.');
        }
    }

    private function assertHandleGeneration(int $generation): void
    {
        if ($generation !== $this->generation) {
            throw new \LogicException('Obtain a new row handle after flushing or clearing the buffer.');
        }
    }

    /** @param MappedKey|array<string, int|string|\Stringable> $owner */
    private function normalizeOwner(MappedKey|array $owner): MappedKey
    {
        if (null === $this->mapping) {
            throw new \LogicException('Owner collections require a mapped row set definition.');
        }

        $key = $owner instanceof MappedKey ? $owner : new MappedKey($owner);
        $this->mapping->assertOwner($key);

        return $key;
    }

    private function membership(?MappedKey $owner): BufferedRowMembership
    {
        return $this->memberships[$owner?->fingerprint() ?? ''] ??= new BufferedRowMembership($owner);
    }

    private function membershipFor(MappedKey $key): ?BufferedRowMembership
    {
        return [] === $this->memberships ? null : $this->memberships[$this->membershipIdForRow($key)] ?? null;
    }

    private function membershipIdForRow(MappedKey $key): string
    {
        $mapping = $this->mapping;

        return null === $mapping || [] === $mapping->ownerColumns ? '' : $mapping->ownerKey($key)->fingerprint();
    }

    private function isVisible(MappedKey $key, ?BufferedRowUpdate $update): bool
    {
        if (true === $update?->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $update?->mode) {
            return false;
        }
        if (null !== $update?->mode) {
            return true;
        }

        $reconciled = $this->membershipFor($key)?->reconciled;

        return null === $reconciled || isset($reconciled[$key->fingerprint()]);
    }

    private function belongsTo(MappedKey $key, ?MappedKey $owner): bool
    {
        return null === $owner || $key->matches($owner);
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function selectedColumns(array $columns, bool $includeKeys = false): array
    {
        if (null === $this->mapping) {
            return $columns;
        }
        foreach ($columns as $column) {
            $this->mapping->column($column);
        }
        if ([] !== $columns) {
            return $columns;
        }

        return $includeKeys ? $this->mapping->columnNames : $this->mapping->nonKeyColumns;
    }

    /** @param int|string|MappedKey|\Stringable|array<string, int|string|\Stringable> $key */
    private function normalizeKey(int|string|MappedKey|\Stringable|array $key): MappedKey
    {
        $keyColumns = $this->mapping->keyColumns ?? $this->adapter->keyColumns();
        if (is_array($key)) {
            $key = new MappedKey($key);
        } elseif (!$key instanceof MappedKey) {
            if (1 !== count($keyColumns)) {
                throw new \InvalidArgumentException(sprintf('A scalar key cannot address row-set key columns [%s].', implode(
                    ', ',
                    $keyColumns,
                )));
            }

            $key = new MappedKey([$keyColumns[0] => $key]);
        }

        if (null === $this->mapping) {
            $key->assertColumns($keyColumns);
        } else {
            $this->mapping->assertKey($key);
        }

        return $key;
    }

    /** @return list<RowUpdatePlan> */
    private function sortedPlans(?MappedKey $owner = null): array
    {
        $plans = [];
        foreach ($this->updates as $update) {
            if (!$this->belongsTo($update->key, $owner)) {
                continue;
            }

            $plan = $update->toPlan();
            if (null !== $plan) {
                $plans[] = $plan;
            }
        }

        usort(
            $plans,
            static fn(RowUpdatePlan $left, RowUpdatePlan $right): int => (
                $left->key->fingerprint() <=> $right->key->fingerprint()
            ),
        );

        return $plans;
    }

    /**
     * @param array<string, mixed>|false $row
     *
     * @return array<string, mixed>|false
     */
    private function applyPlanToRow(array|false $row, RowUpdatePlan $plan): array|false
    {
        if (RowUpdatePlan::MODE_DELETE === $plan->mode) {
            return false;
        }

        $row = false === $row ? [] : $row;
        foreach ($plan->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     *
     * @return array<string, mixed>
     */
    private function projectRow(array $row, array $columns): array
    {
        if ([] === $columns) {
            return $row;
        }

        $projected = [];
        foreach ($columns as $column) {
            $projected[$column] = $row[$column] ?? null;
        }

        return $projected;
    }
}
