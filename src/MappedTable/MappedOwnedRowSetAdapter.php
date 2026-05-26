<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedOwnedRowSetAdapter
{
    /**
     * Return every row currently stored under the given parent key, in deterministic order.
     *
     * @param array<string, int|string> $parentKey
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForParent(array $parentKey): array;

    public function apply(OwnedRowSetUpdatePlan $plan): void;
}
