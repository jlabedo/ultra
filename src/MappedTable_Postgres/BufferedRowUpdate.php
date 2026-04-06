<?php

declare(strict_types=1);

namespace Ultra\MappedTable_Postgres;

/**
 * Internal mutable state backing one fluent row update.
 *
 * @internal
 */
final class BufferedRowUpdate
{
    public const EXPECTED_PRESENT = 'present';
    public const EXPECTED_ABSENT = 'absent';

    public const MODE_UPDATE = 'update';
    public const MODE_INSERT = 'insert';
    public const MODE_DELETE = 'delete';
    public const MODE_REPLACE = 'replace';
    public const MODE_UPSERT = 'upsert';

    public ?string $expectedPresence = null;

    public ?string $mode = null;

    /** @var array<string, mixed> */
    public array $expectedColumns = [];

    /** @var array<string, mixed> */
    public array $setColumns = [];

    public function __construct(
        public readonly string|int $id,
    ) {}
}
