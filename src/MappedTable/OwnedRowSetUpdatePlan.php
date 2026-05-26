<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable plan handed to a MappedOwnedRowSetAdapter at flush time.
 */
final readonly class OwnedRowSetUpdatePlan
{
    /**
     * @param list<OwnedSetReplace> $replacements
     */
    public function __construct(
        public array $replacements,
    ) {}
}
