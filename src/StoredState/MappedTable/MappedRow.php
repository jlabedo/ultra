<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class MappedRow
{
    /** @param array<string, mixed> $columns */
    public function __construct(
        public MappedKey $key,
        public array $columns,
    ) {}
}
