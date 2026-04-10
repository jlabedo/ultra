<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedTableAdapter
{
    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(string $tableName, string $idColumn, string|int $id, array $columns = []): array|false;

    /**
     * @param list<RowUpdatePlan> $rowUpdates
     */
    public function apply(string $tableName, string $idColumn, array $rowUpdates): void;
}
