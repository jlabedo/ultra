<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** The owner reference stored in the immutable key columns of a child row set. */
final readonly class MappedRowSetOwner
{
    /**
     * @param string $definition Parent row set ID.
     * @param array<string, string> $columns Child column to parent key column.
     */
    public function __construct(
        public string $definition,
        public array $columns,
    ) {}

    /** The key may be a complete child row key or its owner key. */
    public function parentKey(MappedKey $key): MappedKey
    {
        $values = [];
        foreach ($this->columns as $child => $parent) {
            $values[$parent] = $key->value($child);
        }

        return new MappedKey($values);
    }
}
