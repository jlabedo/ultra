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

    /** @var list<int|string>|null */
    private ?array $expectedRowIds = null;

    public function __construct(
        private readonly MappedRowSetAdapter $adapter,
    ) {}

    public function withId(int|string $id): PendingRowUpdate
    {
        $updateKey = self::updateKey($id);
        $update = $this->updates[$updateKey] ??= new BufferedRowUpdate($id);

        return new PendingRowUpdate($update);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(int|string $id, array $columns = []): array|false
    {
        $row = $this->adapter->row($id, $columns);
        $update = $this->updates[self::updateKey($id)] ?? null;
        $plan = $update?->toPlan();

        if (null === $plan) {
            return $row;
        }

        return $this->projectRow($this->applyPlanToRow($row, $plan), $columns);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(array $columns = []): array
    {
        $rows = $this->adapter->rows($columns);

        foreach ($this->sortedPlans() as $plan) {
            $rowKey = self::updateKey($plan->id);
            $row = $this->projectRow($this->applyPlanToRow($rows[$rowKey] ?? false, $plan), $columns);

            if (false === $row) {
                unset($rows[$rowKey]);

                continue;
            }

            $rows[$rowKey] = $row;
        }

        ksort($rows, SORT_STRING);

        return $rows;
    }

    /**
     * @param list<int|string> $ids
     */
    public function expectSameRowIds(array $ids): void
    {
        $expectedRowIds = $this->normalizeRowIds($ids);

        if (null !== $this->expectedRowIds && $this->expectedRowIds !== $expectedRowIds) {
            throw new ConcurrencyException('Conflicting row-set id expectations were registered.');
        }

        $this->expectedRowIds = $expectedRowIds;
    }

    public function flush(): void
    {
        $this->adapter->apply(new RowSetUpdatePlan(
            expectedRowIds: $this->expectedRowIds,
            rowUpdates: $this->sortedPlans(),
        ));
    }

    public function hasPendingChanges(): bool
    {
        return null !== $this->expectedRowIds || [] !== $this->sortedPlans();
    }

    public function clear(): void
    {
        $this->updates = [];
        $this->expectedRowIds = null;
    }

    private static function compareIds(int|string $left, int|string $right): int
    {
        if (is_int($left) && is_int($right)) {
            return $left <=> $right;
        }

        return (string) $left <=> (string) $right;
    }

    private static function updateKey(int|string $id): string
    {
        return (string) $id;
    }

    /**
     * @return list<RowUpdatePlan>
     */
    private function sortedPlans(): array
    {
        $updates = array_values($this->updates);
        usort($updates, static fn (BufferedRowUpdate $left, BufferedRowUpdate $right): int => self::compareIds($left->id, $right->id));

        $plans = [];
        foreach ($updates as $update) {
            $plan = $update->toPlan();
            if (null !== $plan) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<int|string>
     */
    private function normalizeRowIds(array $ids): array
    {
        $rowIds = [];
        $seen = [];

        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                throw new \InvalidArgumentException('Expected row ids must be strings or integers.');
            }

            $rowKey = self::updateKey($id);
            if (array_key_exists($rowKey, $seen)) {
                throw new \InvalidArgumentException(sprintf(
                    'Duplicate expected row id "%s".',
                    $rowKey,
                ));
            }

            $seen[$rowKey] = true;
            $rowIds[] = $rowKey;
        }

        usort($rowIds, self::compareIds(...));

        return $rowIds;
    }

    /**
     * @param array<string, mixed>|false $row
     *
     * @return array<string, mixed>|false
     */
    private function applyPlanToRow(array|false $row, RowUpdatePlan $plan): array|false
    {
        return match ($plan->mode) {
            RowUpdatePlan::MODE_DELETE => false,
            RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_REPLACE => $this->newRow($plan),
            RowUpdatePlan::MODE_UPSERT => false === $row
                ? $this->newRow($plan)
                : $this->mergeColumns($row, $plan->setColumns),
            RowUpdatePlan::MODE_UPDATE => false === $row
                ? false
                : $this->mergeColumns($row, $plan->setColumns),
            default => throw new \LogicException(sprintf(
                'Unsupported row update mode "%s".',
                $plan->mode,
            )),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    private function mergeColumns(array $row, array $columns): array
    {
        foreach ($columns as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function newRow(RowUpdatePlan $plan): array
    {
        return $plan->setColumns;
    }

    /**
     * @param list<string>               $columns
     * @param array<string, mixed>|false $row
     *
     * @return array<string, mixed>|false
     */
    private function projectRow(array|false $row, array $columns): array|false
    {
        if (false === $row || [] === $columns) {
            return $row;
        }

        $projectedRow = [];

        foreach ($columns as $column) {
            $projectedRow[$column] = $row[$column] ?? null;
        }

        return $projectedRow;
    }
}
