<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedTableAdapter
{
    /**
     * @param list<RowUpdatePlan> $rowUpdates
     */
    public function apply(string $tableName, string $idColumn, array $rowUpdates): void;
}
