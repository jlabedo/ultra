<?php

declare(strict_types=1);

namespace MappedTableSqlExample;

use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\Testing\SqlTraceEntry;

final readonly class Inspection
{
    /** @param list<SqlTraceEntry> $entries */
    public function __construct(
        public string $tableName,
        public ?RowSetUpdatePlan $plan,
        public array $entries,
        public ?\Throwable $error,
        public ?\Throwable $cleanupError,
    ) {}

    public function succeeded(): bool
    {
        return null === $this->error && null === $this->cleanupError;
    }
}
