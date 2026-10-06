<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;

/**
 * Logical row-set unit of work that buffers conditional row changes and flushes them through an adapter.
 */
final class MappedRowSet implements UnitOfWork
{
    /** @var array<string, BufferedRowUpdate> */
    private array $updates = [];

    /** @var list<MappedKey>|null */
    private ?array $expectedRowKeys = null;

    /** @var list<MappedKey> */
    private array $ignoredRowKeys = [];

    /** @var array<string, MappedKey>|null */
    private ?array $reconciledKeys = null;

    public function __construct(
        private readonly MappedRowSetAdapter $adapter,
    ) {}

    public function withKey(int|string|MappedKey $key): PendingRowUpdate
    {
        $key = $this->normalizeKey($key);
        $updateKey = $key->fingerprint();
        if (!isset($this->updates[$updateKey])) {
            $update = new BufferedRowUpdate($key);
            $update->removedByReconciliation = null !== $this->reconciledKeys && !isset($this->reconciledKeys[$updateKey]);
            $this->updates[$updateKey] = $update;
        }
        $update = $this->updates[$updateKey];

        return new PendingRowUpdate($update);
    }

    /**
     * Upsert the desired keyed rows and delete rows absent from that set in this unit of work's view.
     * No storage is read here. The adapter removes other rows at flush, after checking existing expectations.
     * Unspecified columns are preserved on retained rows.
     *
     * @param iterable<MappedRow> $rows
     */
    public function reconcileRows(iterable $rows): void
    {
        $desiredKeys = [];
        foreach ($rows as $row) {
            $desiredKeys[$row->key->fingerprint()] = $this->normalizeKey($row->key);
            $this->withKey($row->key)->upsert()->setColumns($row->columns);
        }

        foreach ($this->updates as $fingerprint => $update) {
            if (!array_key_exists($fingerprint, $desiredKeys)) {
                // Discard superseded writes, but retain expectations on the committed row.
                $update->mode = null;
                $update->setColumns = [];
                $update->removedByReconciliation = true;
            }
        }
        $this->reconciledKeys = $desiredKeys;
    }

    /** An immutable snapshot of the buffered row changes and expectations. */
    public function updatePlan(): RowSetUpdatePlan
    {
        return new RowSetUpdatePlan($this->expectedRowKeys, $this->sortedPlans(), $this->ignoredRowKeys, $this->retainedRowKeys());
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(int|string|MappedKey $key, array $columns = []): array|false
    {
        $key = $this->normalizeKey($key);
        $retainedKeys = $this->retainedRowKeys();
        if (null !== $retainedKeys && !in_array($key->fingerprint(), $this->keyFingerprints($retainedKeys), true)) {
            return false;
        }
        $row = $this->adapter->row($key, $columns);
        $plan = ($this->updates[$key->fingerprint()] ?? null)?->toPlan();

        if (null === $plan || !$plan->writes()) {
            return $row;
        }

        return $this->projectRow($this->applyPlanToRow($row, $plan), $columns);
    }

    /**
     * @param list<string> $columns
     *
     * @return list<MappedRow>
     */
    public function rows(array $columns = []): array
    {
        $rows = [];
        $retainedKeys = $this->retainedRowKeys();
        $retained = null === $retainedKeys ? null : array_fill_keys($this->keyFingerprints($retainedKeys), true);
        foreach ($this->adapter->rows($columns) as $row) {
            if (null === $retained || isset($retained[$row->key->fingerprint()])) {
                $rows[$row->key->fingerprint()] = $row;
            }
        }

        foreach ($this->sortedWritePlans() as $plan) {
            $fingerprint = $plan->key->fingerprint();
            $projected = $this->projectRow(
                $this->applyPlanToRow($rows[$fingerprint]->columns ?? false, $plan),
                $columns,
            );

            if (false === $projected) {
                unset($rows[$fingerprint]);
            } else {
                $rows[$fingerprint] = new MappedRow($plan->key, $projected);
            }
        }

        $rows = array_values($rows);
        usort($rows, static fn (MappedRow $left, MappedRow $right): int => $left->key->fingerprint() <=> $right->key->fingerprint());

        return $rows;
    }

    /**
     * Expects the row keys this unit of work sees, the ones rows() returns. Keys whose presence is decided by a
     * buffered write are checked immediately; the other keys must match the committed rows at flush.
     *
     * @param list<int|string|MappedKey> $keys
     */
    public function expectSameRowKeys(array $keys): void
    {
        $expected = array_map($this->normalizeKey(...), $keys);
        $fingerprints = $this->keyFingerprints($expected);
        if (count($fingerprints) !== count(array_unique($fingerprints))) {
            throw new \InvalidArgumentException('Duplicate expected row key.');
        }

        $retainedKeys = $this->retainedRowKeys();
        if (null !== $retainedKeys) {
            $actual = $this->keyFingerprints($retainedKeys);
            sort($actual, SORT_STRING);
            sort($fingerprints, SORT_STRING);
            if ($actual !== $fingerprints) {
                throw new ConcurrencyException('Row-set key expectation differs from the reconciled rows in this unit of work.');
            }

            // Reconciliation decides membership locally. Keep any earlier committed expectation unchanged.
            return;
        }

        $expectedSet = array_fill_keys($fingerprints, true);
        $written = [];
        foreach ($this->sortedWritePlans() as $plan) {
            $written[$plan->key->fingerprint()] = $plan->key;
            if ((RowUpdatePlan::MODE_DELETE !== $plan->mode) !== isset($expectedSet[$plan->key->fingerprint()])) {
                throw new ConcurrencyException('Row-set key expectation differs from the rows written in this unit of work.');
            }
        }

        $committed = array_values(array_filter(
            $expected,
            static fn (MappedKey $key): bool => !isset($written[$key->fingerprint()]),
        ));
        usort($committed, static fn (MappedKey $left, MappedKey $right): int => $left->fingerprint() <=> $right->fingerprint());

        if (null === $this->expectedRowKeys) {
            $this->expectedRowKeys = $committed;
            $this->ignoredRowKeys = array_values($written);

            return;
        }

        // Keep the first expectation; the new one must agree with it on rows neither of them ignores.
        $previousIgnored = array_fill_keys($this->keyFingerprints($this->ignoredRowKeys), true);
        $previous = array_values(array_filter(
            $this->keyFingerprints($this->expectedRowKeys),
            static fn (string $fingerprint): bool => !isset($written[$fingerprint]),
        ));
        $current = array_values(array_filter(
            $this->keyFingerprints($committed),
            static fn (string $fingerprint): bool => !isset($previousIgnored[$fingerprint]),
        ));
        if ($previous !== $current) {
            throw new ConcurrencyException('Conflicting row-set key expectations were registered.');
        }
    }

    public function flush(): void
    {
        $this->adapter->apply($this->updatePlan());
    }

    public function hasPendingChanges(): bool
    {
        return null !== $this->reconciledKeys || null !== $this->expectedRowKeys || [] !== $this->sortedPlans();
    }

    public function clear(): void
    {
        $this->updates = [];
        $this->expectedRowKeys = null;
        $this->ignoredRowKeys = [];
        $this->reconciledKeys = null;
    }

    /** @return list<MappedKey>|null */
    private function retainedRowKeys(): ?array
    {
        if (null === $this->reconciledKeys) {
            return null;
        }

        $keys = $this->reconciledKeys;
        foreach ($this->sortedWritePlans() as $plan) {
            if (RowUpdatePlan::MODE_DELETE === $plan->mode) {
                unset($keys[$plan->key->fingerprint()]);
            } else {
                $keys[$plan->key->fingerprint()] = $plan->key;
            }
        }
        ksort($keys, SORT_STRING);

        return array_values($keys);
    }

    private function normalizeKey(int|string|MappedKey $key): MappedKey
    {
        $keyColumns = $this->adapter->keyColumns();
        $scope = $this->adapter->scope();

        if (!$key instanceof MappedKey) {
            $unboundColumns = array_values(array_filter(
                $keyColumns,
                static fn (string $column): bool => null === $scope || !$scope->contains($column),
            ));
            if (1 !== count($unboundColumns)) {
                throw new \InvalidArgumentException(sprintf(
                    'A scalar key cannot address row-set key columns [%s].',
                    implode(', ', $keyColumns),
                ));
            }

            $key = new MappedKey([$unboundColumns[0] => $key]);
            if (null !== $scope) {
                $key = $scope->mergedWith($key);
            }
        }

        $key->assertColumns($keyColumns);
        if (null !== $scope && !$key->matches($scope)) {
            throw new \InvalidArgumentException('Mapped key does not belong to the row-set scope.');
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

        usort($plans, static fn (RowUpdatePlan $left, RowUpdatePlan $right): int => $left->key->fingerprint() <=> $right->key->fingerprint());

        return $plans;
    }

    /**
     * The plans that change the unit of work's view: check-only plans leave the rows as they are.
     *
     * @return list<RowUpdatePlan>
     */
    private function sortedWritePlans(): array
    {
        return array_values(array_filter($this->sortedPlans(), static fn (RowUpdatePlan $plan): bool => $plan->writes()));
    }

    /** @param list<MappedKey> $keys @return list<string> */
    private function keyFingerprints(array $keys): array
    {
        return array_map(static fn (MappedKey $key): string => $key->fingerprint(), $keys);
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
     * @param array<string, mixed>|false $row
     * @param list<string>              $columns
     *
     * @return array<string, mixed>|false
     */
    private function projectRow(array|false $row, array $columns): array|false
    {
        if (false === $row || [] === $columns) {
            return $row;
        }

        $projected = [];
        foreach ($columns as $column) {
            $projected[$column] = $row[$column] ?? null;
        }

        return $projected;
    }
}
