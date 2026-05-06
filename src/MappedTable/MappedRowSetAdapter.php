<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetAdapter
{
    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(int|string $id, array $columns = []): array|false;

    /**
     * @param list<string> $columns
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(array $columns = []): array;

    public function apply(RowSetUpdatePlan $plan): void;
}
