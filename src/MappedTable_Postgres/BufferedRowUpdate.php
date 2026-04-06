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
    /** @var array<string, mixed> */
    public array $expectedColumns = [];

    /** @var array<string, mixed> */
    public array $setColumns = [];

    public function __construct(
        public readonly string|int $id,
    ) {}
}
