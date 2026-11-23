<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Storage;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;

/** @internal Table-specific storage operations used by the common adapter. */
interface RowSetStorage
{
    /** @return list<string> */
    public function keyColumns(): array;

    /**
     * @param list<string> $columns Columns selected from the mapping.
     *
     * @return array<string, mixed>|false
     */
    public function row(MappedKey $key, array $columns): array|false;

    /**
     * @param non-empty-array<string, MappedKey> $keys Indexed by key fingerprint.
     * @param list<string> $columns Columns selected from the mapping.
     * @return array<string, array<string, mixed>> Existing rows indexed by key fingerprint.
     */
    public function rowsByKeys(array $keys, array $columns): array;

    /**
     * @param list<string> $columns Columns selected from the mapping.
     *
     * @return list<MappedRow>
     */
    public function rows(array $columns, ?MappedKey $owner): array;

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
