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
     */
    public function __construct(
        private readonly MappedTableAdapter $adapter,
        private readonly MappedRowSetMetadata $mapping,
    ) {}

    /** @param int|string|MappedKey|\Stringable|array<string, int|string|\Stringable> $key */
    public function withKey(int|string|MappedKey|\Stringable|array $key): MappedRowHandle
    {
        return new MappedRowHandle($this, $this->normalizeKey($key), $this->generation);
    }

    /** @internal True when a buffered deletion, cancellation or reconciliation removes the row. */
    public function hasPendingDeletion(MappedKey $key): bool
    {
        $update = $this->updates[$this->mapping->key($key)->fingerprint()] ?? null;

        return $update?->isDeleted() ?? false;
    }

    /** @internal */
    public function updateForHandle(MappedKey $key, int $generation): BufferedRowUpdate
    {
        $this->assertHandleGeneration($generation);

        return $this->updates[$key->fingerprint()] ??= $this->newUpdate($key);
    }

    private function newUpdate(MappedKey $key): BufferedRowUpdate
    {
        $reconciled = $this->membershipFor($key)?->reconciled;

        return new BufferedRowUpdate(
            $key,
            $this->mapping,
            locallyAbsent: null !== $reconciled && !isset($reconciled[$key->fingerprint()]),
        );
    }

    /**
     * Observe known keys as a group, returning one result per requested key in input order.
     *
     * @param iterable<int|string|MappedKey|\Stringable|array<string, int|string|\Stringable>> $keys
     * @param list<string>|null $columns
     * @return list<array<string, mixed>|null>
     */
    public function observeMany(iterable $keys, ?array $columns = null): array
    {
        return $this->observeManyProjectedColumns($keys, $columns, null);
    }

    /**
     * @internal Allows object views to hydrate additional columns outside their optimistic mapping.
     * @param iterable<int|string|MappedKey|\Stringable|array<string, int|string|\Stringable>> $keys
     * @param list<string>|null $columns
     * @param array<string, true>|null $observedColumns
     * @return list<array<string, mixed>|null>
     */
    public function observeManyProjectedColumns(iterable $keys, ?array $columns, ?array $observedColumns): array
    {
        $order = [];
        $requested = [];
        foreach ($keys as $key) {
            $key = $this->normalizeKey($key);
            $fingerprint = $key->fingerprint();
            $order[] = $fingerprint;
            $requested[$fingerprint] ??= $key;
        }
        if ([] === $requested) {
            return [];
        }

        $projection = [] === $columns
            ? $this->mapping->keyColumns
            : $this->selectedColumns($columns, includeKeys: true);
        $toRead = [];
        $visible = [];
        foreach ($requested as $fingerprint => $key) {
            $pending = $this->updates[$fingerprint] ?? null;
            $visible[$fingerprint] = $this->isVisible($key, $pending);
            if ($visible[$fingerprint] && true !== $pending?->replacesStoredRow()) {
                $toRead[$fingerprint] = $key;
            }
        }
        $stored = [] === $toRead ? [] : $this->adapter->rowsByKeys($this->mapping->table, $toRead, $projection);
        $updates = [];
        $results = [];
        foreach ($requested as $fingerprint => $key) {
            $pending = $this->updates[$fingerprint] ?? null;
            $row = $visible[$fingerprint]
                ? $this->overlayRow($key, $stored[$fingerprint] ?? false, $pending, $projection, trackStorage: true)
                : null;
            $updates[$fingerprint] = $this->observedUpdate(
                $key,
                $pending,
                $row['storedValues'] ?? null,
                $observedColumns,
                $row['initializedColumns'] ?? [],
            );
            $results[$fingerprint] = $row['values'] ?? null;
        }
        foreach ($updates as $fingerprint => $update) {
            $this->updates[$fingerprint] = $update;
        }

        return array_map(static fn(string $fingerprint): ?array => $results[$fingerprint], $order);
    }

    /**
     * @param array<string, mixed>|null $storedValues
     * @param array<string, true>|null $observedColumns
     * @param array<string, true> $initializedColumns
     */
    private function observedUpdate(
        MappedKey $key,
        ?BufferedRowUpdate $pending,
        ?array $storedValues,
        ?array $observedColumns,
        array $initializedColumns,
    ): BufferedRowUpdate {
        $candidate = null === $pending ? $this->newUpdate($key) : clone $pending;
        $candidate->applyObservation($storedValues, $observedColumns, $initializedColumns);

        return $candidate;
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

            $values = [];
            foreach ($row->columns as $column => $value) {
                $values[$column] = $this->mapping->normalizeWritableValue($column, $value);
            }
            $fingerprint = $key->fingerprint();
            $desiredKeys[$fingerprint] = $key;
            $desiredColumns[$fingerprint] = [...($desiredColumns[$fingerprint] ?? []), ...$values];
        }

        foreach ($desiredKeys as $fingerprint => $key) {
            $update = $this->updates[$fingerprint] ?? $this->newUpdate($key);
            $update->upsert();
            $update->setColumns($desiredColumns[$fingerprint]);
            $this->updates[$fingerprint] = $update;
        }

        foreach ($this->updates as $fingerprint => $update) {
            if (isset($desiredKeys[$fingerprint]) || !$this->belongsTo($update->key, $owner)) {
                continue;
            }

            // Discard superseded writes, retaining expectations on the committed row.
            $update->excludeFromReconciliation();
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
     * @param array<string, true>|null $observedColumns
     * @return array<string, mixed>|null
     */
    public function readForHandle(
        MappedKey $key,
        int $generation,
        ?array $columns,
        bool $observe = false,
        ?array $observedColumns = null,
    ): ?array {
        $this->assertHandleGeneration($generation);
        $projection = [] === $columns
            ? $this->mapping->keyColumns
            : $this->selectedColumns($columns, includeKeys: true);
        $fingerprint = $key->fingerprint();
        $update = $this->updates[$fingerprint] ?? null;
        $row = null;
        if ($this->isVisible($key, $update)) {
            $stored = true === $update?->replacesStoredRow()
                ? false
                : $this->adapter->row($this->mapping->table, $key, $projection);
            $row = $this->overlayRow($key, $stored, $update, $projection, trackStorage: $observe);
        }
        if ($observe) {
            $this->updates[$fingerprint] = $this->observedUpdate(
                $key,
                $update,
                $row['storedValues'] ?? null,
                $observedColumns,
                $row['initializedColumns'] ?? [],
            );
        }

        return $row['values'] ?? null;
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
        $selected = [] === $projection ? $this->mapping->keyColumns : $projection;
        $rows = [];
        foreach ($this->adapter->rows($this->mapping->table, $selected, $owner) as $row) {
            $storedKey = $this->mapping->key($row->key);
            $fingerprint = $storedKey->fingerprint();
            $update = $this->updates[$fingerprint] ?? null;
            if (!$this->isVisible($storedKey, $update)) {
                continue;
            }

            $key = true === $update?->writes() ? $update->key : $storedKey;
            $view = $this->overlayRow($key, $row->columns, $update, $projection);
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

            $view = $this->overlayRow($update->key, false, $update, $projection);
            if (null !== $view) {
                $rows[$fingerprint] = new MappedRow($update->key, $view['values']);
            }
        }
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /**
     * @param array<string, mixed>|false $stored False indicates no stored row.
     * @param list<string> $projection
     * @return array{values: array<string, mixed>, storedValues: array<string, mixed>, initializedColumns: array<string, true>}|null
     */
    private function overlayRow(
        MappedKey $key,
        array|false $stored,
        ?BufferedRowUpdate $update,
        array $projection,
        bool $trackStorage = false,
    ): ?array {
        $writes = true === $update?->writes();
        if (false === $stored && !$writes) {
            return null;
        }

        $replacesStored = true === $update?->replacesStoredRow();
        $initializesIncrements = true === $update?->initializesIncrements() && (false === $stored || $replacesStored);
        $stored = false === $stored || $replacesStored ? [] : $stored;
        $written = $writes ? $update->writtenColumns() : [];
        $increments = $update?->increments() ?? [];
        $values = [];
        $storedValues = [];
        $initializedColumns = [];
        foreach ($projection as $column) {
            if ($key->contains($column)) {
                $values[$column] = $key->value($column);
                continue;
            }
            if (array_key_exists($column, $written)) {
                $values[$column] = $written[$column];
            } elseif (array_key_exists($column, $stored)) {
                $values[$column] = isset($increments[$column])
                    ? $this->mapping->column($column)->increment($stored[$column], $increments[$column])
                    : $stored[$column];
                if ($trackStorage) {
                    $storedValues[$column] = $stored[$column];
                }
            } elseif ($initializesIncrements && isset($increments[$column])) {
                $values[$column] = $this->mapping->column($column)->increment(0, $increments[$column]);
                if ($trackStorage && !$replacesStored) {
                    $initializedColumns[$column] = true;
                }
            }
        }

        return ['values' => $values, 'storedValues' => $storedValues, 'initializedColumns' => $initializedColumns];
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
        return $this->mapping;
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
        if ([] !== $this->mapping->ownerColumns) {
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
        return $this->mapping->owner($owner);
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

        return [] === $mapping->ownerColumns ? '' : $mapping->ownerKey($key)->fingerprint();
    }

    private function isVisible(MappedKey $key, ?BufferedRowUpdate $update): bool
    {
        if (true === $update?->isDeleted()) {
            return false;
        }
        if (true === $update?->hasWriteIntent()) {
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
     * @return list<string>
     */
    private function selectedColumns(?array $columns, bool $includeKeys = false): array
    {
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
        if ($key instanceof MappedKey || is_array($key)) {
            return $this->mapping->key($key);
        }
        $keyColumns = $this->mapping->keyColumns;
        if (1 !== count($keyColumns)) {
            throw new \InvalidArgumentException(sprintf('A scalar key cannot address row-set key columns [%s].', implode(
                ', ',
                $keyColumns,
            )));
        }

        $column = $keyColumns[0];

        return new MappedKey([$column => $this->mapping->column($column)->key($key)]);
    }

    /** @return list<RowUpdatePlan> */
    private function sortedPlans(): array
    {
        $plans = [];
        foreach ($this->updates as $fingerprint => $update) {
            $plan = $update->toPlan();
            if (null !== $plan) {
                $plans[$fingerprint] = $plan;
            }
        }

        ksort($plans, SORT_STRING);

        return array_values($plans);
    }
}
