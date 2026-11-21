<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class MappedRow
{
    public MappedKey $key;

    /**
     * @param MappedKey|array<string, int|string|\Stringable> $key
     * @param array<string, mixed> $columns
     */
    public function __construct(
        MappedKey|array $key,
        public array $columns,
    ) {
        $this->key = $key instanceof MappedKey ? $key : new MappedKey($key);
    }
}
