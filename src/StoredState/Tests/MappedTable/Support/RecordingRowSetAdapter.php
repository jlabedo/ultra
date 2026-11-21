<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Tests\Support;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\RowSetUpdatePlan;

final class RecordingRowSetAdapter implements MappedRowSetAdapter
{
    public int $reads = 0;

    /** @var list<list<string>> */
    public array $rowProjections = [];

    /** @var list<RowSetUpdatePlan> */
    public array $batches = [];

    public function keyColumns(): array
    {
        return ['id'];
    }

    public function row(MappedKey $key, array $columns = []): array|false
    {
        ++$this->reads;
        $this->rowProjections[] = $columns;
        return false;
    }

    public function rows(array $columns = [], ?MappedKey $owner = null): array
    {
        ++$this->reads;
        return [];
    }

    public function activeTransactionResource(): object
    {
        throw new \LogicException('Recording adapter has no transaction.');
    }

    public function check(RowSetUpdatePlan $plan): void
    {
        $this->batches[] = $plan;
    }

    public function writeRows(array $writes): void {}

    public function deleteExcludedRows(RowSetUpdatePlan $plan): void {}
}
