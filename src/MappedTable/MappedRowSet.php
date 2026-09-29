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

    public function __construct(
        private readonly MappedRowSetAdapter $adapter,
    ) {}

    public function withKey(int|string|MappedKey $key): PendingRowUpdate
    {
        $key = $this->normalizeKey($key);
        $updateKey = $key->fingerprint();
        $update = $this->updates[$updateKey] ??= new BufferedRowUpdate($key);

        return new PendingRowUpdate($update);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(int|string|MappedKey $key, array $columns = []): array|false
    {
        $key = $this->normalizeKey($key);
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
        foreach ($this->adapter->rows($columns) as $row) {
            $rows[$row->key->fingerprint()] = $row;
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
        $this->adapter->apply(new RowSetUpdatePlan(
            expectedRowKeys: $this->expectedRowKeys,
            rowUpdates: $this->sortedPlans(),
            ignoredRowKeys: $this->ignoredRowKeys,
        ));
    }

    public function hasPendingChanges(): bool
    {
        return null !== $this->expectedRowKeys || [] !== $this->sortedPlans();
    }

    public function clear(): void
    {
        $this->updates = [];
        $this->expectedRowKeys = null;
        $this->ignoredRowKeys = [];
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
