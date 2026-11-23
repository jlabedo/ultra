<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Buffer\BufferedMembership;
use Ultra\MappedTable\Buffer\BufferedRowUpdate;
use Ultra\MappedTable\Plan\RowMembershipPlan;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;

/**
 * Logical row set that buffers conditional row changes for MappedTableUnitOfWork.
 */
final class MappedRowSet
{
    /** @var array<string, BufferedRowUpdate> */
    private array $updates = [];

    private bool $coordinated = false;

    private int $generation = 0;

    /** @var array<string, BufferedMembership> */
    private array $memberships = [];

    /**
     * @internal Create row sets through the common adapter.
     * @param list<string> $keyColumns
     */
    public function __construct(
        private readonly MappedTableAdapter $adapter,
        private readonly string $table,
        private readonly array $keyColumns,
        private readonly ?MappedRowSetMetadata $mapping,
    ) {}

    /** @param int|string|MappedKey|\Stringable|array<string, int|string|\Stringable> $key */
    public function withKey(int|string|MappedKey|\Stringable|array $key): MappedRowHandle
    {
        return new MappedRowHandle($this, $this->normalizeKey($key), $this->generation, $this->table, $this->mapping);
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
    public function readForHandle(MappedKey $key, int $generation, ?array $columns, bool $trackStorage = false): ?array
    {
        $this->assertHandleGeneration($generation);
        $keyOnly = [] === $columns;
        $projection = $keyOnly ? $this->keyColumns : $this->selectedColumns($columns, includeKeys: true);
        $update = $this->updates[$key->fingerprint()] ?? null;
        if (!$this->isVisible($key, $update)) {
            return null;
        }

        $plan = $update?->toPlan();
        $stored = $this->replacesStoredRow($plan) ? false : $this->adapter->row($this->table, $key, $projection ?? []);
        $row = $this->overlayRow($key, $stored, $plan, $projection, trackStorage: $trackStorage);
        if ($keyOnly && null !== $row) {
            $row['values'] = $key->values;
        }

        return $row;
    }

    /**
     * Reads the owner's visible rows. Omit the projection or pass null for all mapped columns.
     * An empty projection returns complete keys with empty column maps.
     *
     * @param MappedKey|array<string, int|string|\Stringable> $owner
     * @param list<string>|null $columns
     * @return list<MappedRow>
     */
    public function rowsForOwner(MappedKey|array $owner, ?array $columns = null): array
    {
        return $this->readOwnerRows($this->normalizeOwner($owner), $columns);
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
        $keys = array_map(static fn(MappedRow $row): MappedKey => $row->key, $this->readOwnerRows($owner, []));
        $this->expectKeys($owner, $keys);

        return $keys;
    }

    /**
     * @param list<string>|null $columns
     * @return list<MappedRow>
     */
    private function readOwnerRows(MappedKey $owner, ?array $columns): array
    {
        $projection = $this->selectedColumns($columns);
        assert(null !== $projection, 'Owner reads require a mapped row set definition.');
        $selected = [] === $projection ? $this->keyColumns : $projection;
        $rows = [];
        foreach ($this->adapter->rows($this->table, $selected, $owner) as $row) {
            $fingerprint = $row->key->fingerprint();
            $update = $this->updates[$fingerprint] ?? null;
            if (!$this->isVisible($row->key, $update)) {
                continue;
            }

            $this->mapping?->assertKey($row->key);
            $plan = $update?->toPlan();
            $key = true === $plan?->writes() ? $plan->key : $row->key;
            $view = $this->overlayRow($key, $row->columns, $plan, $projection);
            assert(null !== $view, 'A visible stored row remains present.');
            $rows[$fingerprint] = new MappedRow($key, $view['values']);
        }

        foreach ($this->updates as $fingerprint => $update) {
            if (
                isset($rows[$fingerprint])
                || !$this->belongsTo($update->key, $owner)
                || !$this->isVisible($update->key, $update)
            ) {
                continue;
            }

            $view = $this->overlayRow($update->key, false, $update->toPlan(), $projection);
            if (null !== $view) {
                $rows[$fingerprint] = new MappedRow($update->key, $view['values']);
            }
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /**
     * @param array<string, mixed>|false $stored False indicates no stored row.
     * @param list<string>|null $projection Null selects all known values.
     * @return array{values: array<string, mixed>, storedValues: array<string, mixed>}|null
     */
    private function overlayRow(
        MappedKey $key,
        array|false $stored,
        ?RowUpdatePlan $plan,
        ?array $projection,
        bool $trackStorage = false,
    ): ?array {
        $writes = true === $plan?->writes();
        if (false === $stored && !$writes) {
            return null;
        }

        $stored = false === $stored || $this->replacesStoredRow($plan) ? [] : $stored;
        $written = $writes ? [...$plan->setColumns, ...$key->values] : [];
        $values = [];
        $storedValues = [];
        foreach ($projection ?? array_keys($stored + $written + $key->values) as $column) {
            $isKey = $key->contains($column);
            $fromStorage = array_key_exists($column, $stored);
            if ($fromStorage) {
                $this->mapping?->assertValue($column, $stored[$column]);
            }
            if (array_key_exists($column, $written)) {
                $values[$column] = $written[$column];
            } elseif ($fromStorage) {
                $values[$column] = $stored[$column];
                if ($trackStorage && !$isKey) {
                    $storedValues[$column] = $stored[$column];
                }
            } elseif ($isKey) {
                $values[$column] = $key->value($column);
            }
        }

        return ['values' => $values, 'storedValues' => $storedValues];
    }

    private function replacesStoredRow(?RowUpdatePlan $plan): bool
    {
        return match ($plan?->mode) {
            RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_REPLACE => true,
            default => false,
        };
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
        $membership = $this->memberships[$id] ?? new BufferedMembership($owner);
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
        return (
            $this->mapping ?? new MappedRowSetMetadata(
                $this->table,
                [],
                $this->keyColumns,
                [],
                null,
                $this->keyColumns,
                [],
                array_fill_keys($this->keyColumns, true),
                [],
            )
        );
    }

    /** @internal */
    public function assertCanEnroll(MappedTableAdapter $adapter): void
    {
        if ($this->coordinated) {
            throw new \LogicException('This mapped row set already has a flush coordinator.');
        }
        if ($this->adapter !== $adapter) {
            throw new \LogicException('Coordinated row sets must be bound to the same mapped table adapter.');
        }
    }

    /** @internal */
    public function enrollInCoordinator(): void
    {
        $this->coordinated = true;
    }

    public function hasPendingChanges(): bool
    {
        if ([] !== $this->memberships) {
            return true;
        }

        foreach ($this->updates as $update) {
            if ($update->hasPendingChanges()) {
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

    private function membership(?MappedKey $owner): BufferedMembership
    {
        return $this->memberships[$owner?->fingerprint() ?? ''] ??= new BufferedMembership($owner);
    }

    private function membershipFor(MappedKey $key): ?BufferedMembership
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
     * @param list<string>|null $columns
     * @return list<string>|null
     */
    private function selectedColumns(?array $columns, bool $includeKeys = false): ?array
    {
        if (null === $this->mapping) {
            return $columns;
        }
        if (null === $columns) {
            return $includeKeys ? $this->mapping->columnNames : $this->mapping->nonKeyColumns;
        }

        foreach ($columns as $column) {
            $this->mapping->column($column);
        }

        return $columns;
    }

    /** @param int|string|MappedKey|\Stringable|array<string, int|string|\Stringable> $key */
    private function normalizeKey(int|string|MappedKey|\Stringable|array $key): MappedKey
    {
        $keyColumns = $this->mapping->keyColumns ?? $this->keyColumns;
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
    private function sortedPlans(): array
    {
        $plans = [];
        foreach ($this->updates as $update) {
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
}
