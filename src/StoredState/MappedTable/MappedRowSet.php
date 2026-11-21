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

    private BufferedRowMembership $membership;

    /** @var array<string, BufferedRowMembership> */
    private array $collections = [];

    public function __construct(
        private readonly MappedRowSetAdapter $adapter,
        private readonly ?MappedRowSetMapping $mapping = null,
    ) {
        if (null !== $mapping && (null !== $adapter->scope() || $mapping->keyColumns !== $adapter->keyColumns())) {
            throw new \InvalidArgumentException('A defined row set needs an unscoped adapter with the declared key.');
        }
        if (null !== $mapping && [] !== $mapping->ownerColumns && !$adapter instanceof OwnerMappedRowSetAdapter) {
            throw new \InvalidArgumentException('The mapped adapter must support owner collections.');
        }

        $this->membership = new BufferedRowMembership();
    }

    public function withKey(int|string|MappedKey|\Stringable $key): PendingRowUpdate
    {
        $key = $this->normalizeKey($key);
        $fingerprint = $key->fingerprint();
        if (!isset($this->updates[$fingerprint])) {
            $update = new BufferedRowUpdate($key);
            $reconciled = $this->membershipFor($key)?->reconciled;
            $update->removedByReconciliation = null !== $reconciled && !isset($reconciled[$fingerprint]);
            $this->updates[$fingerprint] = $update;
        }

        return new PendingRowUpdate(
            $this->updates[$fingerprint],
            $this->mapping->table ?? 'mapped row set',
            $this->mapping,
        );
    }

    /** @param iterable<MappedRow> $rows */
    public function reconcileRows(iterable $rows): void
    {
        $this->assertLegacyMembership();
        $this->reconcile($this->membership, $rows);
    }

    /** @param iterable<MappedRow> $rows */
    public function reconcileForOwner(MappedKey $owner, iterable $rows): void
    {
        $membership = $this->collection($owner);
        $this->reconcile($membership, $rows);
        $this->collections[$owner->fingerprint()] = $membership;
    }

    /** @param iterable<MappedRow> $rows */
    private function reconcile(BufferedRowMembership $membership, iterable $rows): void
    {
        $desiredKeys = [];
        $desiredColumns = [];
        foreach ($rows as $row) {
            $key = $this->normalizeKey($row->key);
            if (null !== $membership->owner && !$key->matches($membership->owner)) {
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
            if (isset($desiredKeys[$fingerprint]) || !$this->belongsTo($update->key, $membership->owner)) {
                continue;
            }

            // Discard superseded writes, retaining expectations on the committed row.
            $update->mode = null;
            $update->setColumns = [];
            $update->removedByReconciliation = true;
        }
        $membership->reconciled = $desiredKeys;
    }

    /** An immutable snapshot of the buffered row changes and expectations. */
    public function updatePlan(): RowSetUpdatePlan
    {
        $plans = $this->sortedPlans();
        $collections = $this->collections;
        ksort($collections, SORT_STRING);
        $ownerPlans = [];
        $updatesByOwner = [];
        if ([] !== $collections) {
            foreach ($this->updates as $update) {
                $owner = $this->ownerKey($update->key);
                $updatesByOwner[$owner->fingerprint()][] = $update;
            }
        }
        foreach ($collections as $fingerprint => $collection) {
            if (!$collection->hasWork() || null === $collection->owner) {
                continue;
            }

            $retained = $collection->retained($updatesByOwner[$fingerprint] ?? []);
            $ownerPlans[] = new OwnerCollectionPlan(
                $collection->owner,
                $collection->expected,
                $collection->ignored,
                null === $retained ? null : array_values($retained),
            );
        }

        $retained = $this->membership->retained($this->updates);

        return new RowSetUpdatePlan(
            $this->membership->expected,
            $plans,
            $this->membership->ignored,
            null === $retained ? null : array_values($retained),
            $ownerPlans,
        );
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(int|string|MappedKey|\Stringable $key, array $columns = []): array|false
    {
        $key = $this->normalizeKey($key);
        $columns = $this->selectedColumns($columns, includeKeys: true);
        $update = $this->updates[$key->fingerprint()] ?? null;
        if (!$this->isVisible($key, $update)) {
            return false;
        }

        $mapping = $this->mapping;
        $plan = $update?->toPlan();
        $row = null !== $mapping
        && in_array($plan?->mode, [RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_REPLACE], true)
            ? false
            : $this->adapter->row($key, $columns);
        if (false !== $row && null !== $mapping) {
            $row = $this->projectRow($row, $columns);
            $mapping->assertValues($row);
        }
        if (null === $plan || !$plan->writes()) {
            return $row;
        }

        $row = $this->applyPlanToRow($row, $plan);
        if (false === $row) {
            return false;
        }

        return $this->projectRow(null === $mapping ? $row : [...$row, ...$key->values], $columns);
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
     * @param list<string> $columns
     * @return list<MappedRow>
     */
    public function rowsForOwner(MappedKey $owner, array $columns = []): array
    {
        $this->assertOwner($owner);

        return $this->readRows($owner, $columns);
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
        $adapter = $this->adapter;
        $committed =
            null !== $owner && $adapter instanceof OwnerMappedRowSetAdapter
                ? $adapter->rowsForOwner($owner, $selected)
                : $adapter->rows($selected);
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

    /** @param list<int|string|MappedKey|\Stringable> $keys */
    public function expectSameRowKeys(array $keys): void
    {
        $this->assertLegacyMembership();
        $this->membership->expect(array_map($this->normalizeKey(...), $keys), array_values($this->updates));
    }

    /** @param list<MappedKey> $keys */
    public function expectSameRowKeysForOwner(MappedKey $owner, array $keys): void
    {
        $membership = $this->collection($owner);
        $expected = [];
        foreach ($keys as $key) {
            $key = $this->normalizeKey($key);
            if (!$key->matches($owner)) {
                throw new \InvalidArgumentException('Expected mapped row key does not belong to the declared owner.');
            }
            $expected[] = $key;
        }
        $updates = array_values(array_filter(
            $this->updates,
            static fn(BufferedRowUpdate $update): bool => $update->key->matches($owner),
        ));
        $membership->expect($expected, $updates);
        $this->collections[$owner->fingerprint()] = $membership;
    }

    public function flush(): void
    {
        if (!$this->hasPendingChanges()) {
            return;
        }

        $this->adapter->apply($this->updatePlan());
        $this->clear();
    }

    public function hasPendingChanges(): bool
    {
        if ($this->membership->hasWork() || [] !== $this->collections) {
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
        $this->updates = [];
        $this->membership = new BufferedRowMembership();
        $this->collections = [];
    }

    private function assertLegacyMembership(): void
    {
        if (null !== $this->mapping) {
            throw new \LogicException('Defined row sets support membership operations only by declared owner.');
        }
    }

    private function assertOwner(MappedKey $owner): void
    {
        if (null === $this->mapping) {
            throw new \LogicException('Owner collections require a mapped row set definition.');
        }

        $this->mapping->assertOwner($owner);
    }

    private function collection(MappedKey $owner): BufferedRowMembership
    {
        $this->assertOwner($owner);

        return $this->collections[$owner->fingerprint()] ?? new BufferedRowMembership($owner);
    }

    private function membershipFor(MappedKey $key): ?BufferedRowMembership
    {
        if (null === $this->mapping) {
            return $this->membership;
        }
        if ([] === $this->mapping->ownerColumns || [] === $this->collections) {
            return null;
        }

        return $this->collections[$this->ownerKey($key)->fingerprint()] ?? null;
    }

    private function ownerKey(MappedKey $key): MappedKey
    {
        assert(null !== $this->mapping, 'An owner key requires a defined row set.');

        return new MappedKey(array_intersect_key($key->values, array_flip($this->mapping->ownerColumns)));
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

        $columns = array_keys($this->mapping->columns);

        return $includeKeys ? $columns : array_values(array_diff($columns, $this->mapping->keyColumns));
    }

    private function normalizeKey(int|string|MappedKey|\Stringable $key): MappedKey
    {
        $keyColumns = $this->adapter->keyColumns();
        $scope = $this->adapter->scope();

        if (!$key instanceof MappedKey) {
            $unboundColumns = array_values(array_filter(
                $keyColumns,
                static fn(string $column): bool => null === $scope || !$scope->contains($column),
            ));
            if (1 !== count($unboundColumns)) {
                throw new \InvalidArgumentException(sprintf('A scalar key cannot address row-set key columns [%s].', implode(
                    ', ',
                    $keyColumns,
                )));
            }

            $key = new MappedKey([$unboundColumns[0] => $key]);
            if (null !== $scope) {
                $key = $scope->mergedWith($key);
            }
        }

        $key->assertColumns($keyColumns);
        $this->mapping?->assertKey($key);
        if (null !== $scope && !$key->matches($scope)) {
            throw new \InvalidArgumentException('Mapped key does not belong to the row-set scope.');
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
