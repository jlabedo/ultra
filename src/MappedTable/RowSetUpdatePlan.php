<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable row-set mutation plan prepared by MappedRowSet before it reaches an adapter.
 */
final readonly class RowSetUpdatePlan
{
    /**
     * @param list<int|string>|null $expectedRowIds
     * @param list<RowUpdatePlan>   $rowUpdates
     */
    public function __construct(
        public ?array $expectedRowIds,
        public array $rowUpdates,
    ) {}
}
