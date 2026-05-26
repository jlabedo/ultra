<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable record describing a "replace the entire set of rows owned by this parent key" operation.
 */
final readonly class OwnedSetReplace
{
    /**
     * @param array<string, int|string>      $parentKey
     * @param list<array<string, mixed>>     $rows
     */
    public function __construct(
        public array $parentKey,
        public array $rows,
    ) {}
}
