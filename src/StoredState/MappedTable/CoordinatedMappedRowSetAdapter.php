<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface CoordinatedMappedRowSetAdapter extends MappedRowSetAdapter
{
    /** The active transaction's connection or in-memory database. Throws when there is no active transaction. */
    public function activeTransactionResource(): object;

    /** Verify check-only rows and memberships, and take any intrinsic locks needed for this plan. */
    public function check(RowSetUpdatePlan $plan): void;

    /**
     * Apply writes with their own predicates, in the supplied order. Called after check().
     *
     * @param list<RowUpdatePlan> $writes
     */
    public function writeRows(array $writes): void;

    /** Delete rows excluded by reconciliations, keeping the plan's final retained keys. Called after check(). */
    public function deleteExcludedRows(RowSetUpdatePlan $plan): void;
}
