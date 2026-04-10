<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Per-table unit of work that buffers conditional row changes and flushes them through an adapter.
 */
final class MappedTable implements UnitOfWork
{
    /** @var array<string, BufferedRowUpdate> */
    private array $updates = [];

    public function __construct(
        private readonly MappedTableAdapter $adapter,
        private readonly string $tableName,
        private readonly string $idColumn = 'id',
    ) {}

    public function withId(int|string $id): PendingRowUpdate
    {
        $updateKey = self::updateKey($id);
        $update = $this->updates[$updateKey] ??= new BufferedRowUpdate($id);

        return new PendingRowUpdate($update, $this->tableName);
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(int|string $id, array $columns = []): array|false
    {
        $row = $this->adapter->row($this->tableName, $this->idColumn, $id, $columns);
        $update = $this->updates[self::updateKey($id)] ?? null;
        $plan = $update?->toPlan();

        if (null === $plan) {
            return $row;
        }

        return $this->projectRow($this->applyPlanToRow($row, $plan), $columns);
    }

    public function flush(): void
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

        $this->adapter->apply($this->tableName, $this->idColumn, $plans);
    }

    public function clear(): void
    {
        $this->updates = [];
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
                'Unsupported row update mode "%s" for table "%s".',
                $plan->mode,
                $this->tableName,
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
        $row = [$this->idColumn => $plan->id];

        foreach ($plan->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
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

        $projectedRow = [
            $this->idColumn => $row[$this->idColumn],
        ];

        foreach ($columns as $column) {
            $projectedRow[$column] = $row[$column] ?? null;
        }

        return $projectedRow;
    }
}
