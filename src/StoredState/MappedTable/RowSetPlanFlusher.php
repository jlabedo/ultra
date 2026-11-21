<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** @internal Executes plans ordered by persistence dependency. The caller owns the transaction. */
final class RowSetPlanFlusher
{
    /**
     * @template T of array-key
     * @param array<T, RowSetUpdatePlan> $plans
     * @param array<T, MappedRowSetAdapter> $adapters
     */
    public static function flush(array $plans, array $adapters): void
    {
        foreach ($plans as $id => $plan) {
            $adapters[$id]->check($plan);
        }

        foreach (array_reverse($plans, preserve_keys: true) as $id => $plan) {
            $adapter = $adapters[$id];
            $adapter->writeRows(array_values(array_filter(
                $plan->rowUpdates,
                static fn(RowUpdatePlan $row): bool => RowUpdatePlan::MODE_DELETE === $row->mode,
            )));
            $adapter->deleteExcludedRows($plan);
        }

        foreach ($plans as $id => $plan) {
            $adapters[$id]->writeRows(array_values(array_filter(
                $plan->rowUpdates,
                static fn(RowUpdatePlan $row): bool => $row->writes() && RowUpdatePlan::MODE_DELETE !== $row->mode,
            )));
        }
    }
}
