<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\RowSetUpdatePlan;

final class RecordingRowSetAdapter implements MappedRowSetAdapter
{
    public int $reads = 0;
    /** @var list<RowSetUpdatePlan> */
    public array $batches = [];

    public function keyColumns(): array
    {
        return ['id'];
    }

    public function scope(): ?MappedKey
    {
        return null;
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        ++$this->reads;
        return false;
    }

    public function rows(array $columns = []): array
    {
        ++$this->reads;
        return [];
    }

    public function apply(RowSetUpdatePlan $plan): void
    {
        $this->batches[] = $plan;
    }
}
