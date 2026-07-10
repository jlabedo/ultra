<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable row mutation plan prepared by MappedRowSet before it reaches an adapter.
 */
final readonly class RowUpdatePlan
{
    public const EXPECTED_PRESENT = 'present';
    public const EXPECTED_ABSENT = 'absent';

    public const MODE_UPDATE = 'update';
    public const MODE_INSERT = 'insert';
    public const MODE_DELETE = 'delete';
    public const MODE_REPLACE = 'replace';
    public const MODE_UPSERT = 'upsert';

    /**
     * @param array<string, mixed> $expectedColumns
     * @param array<string, mixed> $setColumns
     */
    public function __construct(
        public MappedKey $key,
        public ?string $expectedPresence,
        public string $mode,
        public array $expectedColumns,
        public array $setColumns,
    ) {}
}
