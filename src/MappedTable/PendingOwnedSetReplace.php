<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Fluent handle returned by MappedOwnedRowSet::withParent().
 */
final class PendingOwnedSetReplace
{
    /**
     * @param \Closure(list<array<string, mixed>>): void $writer
     */
    public function __construct(
        private readonly \Closure $writer,
    ) {}

    /**
     * Buffer a "replace the entire set of rows owned by this parent" operation.
     *
     * Calling replace() a second time for the same parent overwrites the previously buffered set.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function replace(array $rows): void
    {
        ($this->writer)($rows);
    }
}
