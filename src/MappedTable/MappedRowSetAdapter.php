<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetAdapter
{
    /** @return list<string> */
    public function keyColumns(): array;

    public function scope(): ?MappedKey;

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(MappedKey $key, array $columns = []): array|false;

    /**
     * @param list<string> $columns
     *
     * @return list<MappedRow>
     */
    public function rows(array $columns = []): array;

    public function apply(RowSetUpdatePlan $plan): void;
}
