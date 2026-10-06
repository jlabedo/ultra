<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable row-set mutation plan prepared by MappedRowSet before it reaches an adapter.
 */
final readonly class RowSetUpdatePlan
{
    /**
     * @param list<MappedKey>|null $expectedRowKeys committed row keys expected at flush, ignoring $ignoredRowKeys
     * @param list<RowUpdatePlan>  $rowUpdates
     * @param list<MappedKey>      $ignoredRowKeys  rows whose presence was decided by this unit of work when the
     *                                               expectation was registered
     * @param list<MappedKey>|null $retainedRowKeys null leaves membership unchanged; otherwise remove other rows
     *                                             in the adapter's scope after checks and writes
     */
    public function __construct(
        public ?array $expectedRowKeys,
        public array $rowUpdates,
        public array $ignoredRowKeys = [],
        public ?array $retainedRowKeys = null,
    ) {}
}
