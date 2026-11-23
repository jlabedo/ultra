<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class QueryGuardDependency
{
    /**
     * @param string $rowSet Mapped row set ID.
     * @param list<string>|null $columns Null watches every column. An empty list watches membership and the guard value.
     */
    public function __construct(
        public string $rowSet,
        public string $valueColumn,
        public ?array $columns = null,
    ) {}
}
