<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable plan handed to a MappedOwnedRowSetAdapter at flush time.
 *
 * Adapters MUST apply `$parentMatchDeletes` first, then `$replacements`. The
 * MappedOwnedRowSet enforces this ordering by dropping any buffered replace
 * whose parent matches a queued partial-key delete at queue time — so any
 * replace surviving into the plan is intentionally meant to win.
 */
final readonly class OwnedRowSetUpdatePlan
{
    /**
     * @param list<OwnedSetReplace>             $replacements
     * @param list<OwnedSetParentMatchDelete>   $parentMatchDeletes
     */
    public function __construct(
        public array $replacements,
        public array $parentMatchDeletes = [],
    ) {}
}
