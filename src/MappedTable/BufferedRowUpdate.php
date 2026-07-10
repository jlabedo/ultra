<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Internal mutable state backing one fluent row update.
 *
 * @internal
 */
final class BufferedRowUpdate
{
    public ?string $expectedPresence = null;

    public ?string $mode = null;

    /** @var array<string, mixed> */
    public array $expectedColumns = [];

    /** @var array<string, mixed> */
    public array $setColumns = [];

    public function __construct(
        public readonly MappedKey $key,
    ) {}

    public function toPlan(): ?RowUpdatePlan
    {
        if (null === $this->mode) {
            return null;
        }

        if (RowUpdatePlan::MODE_UPDATE === $this->mode && [] === $this->setColumns) {
            return null;
        }

        return new RowUpdatePlan(
            key: $this->key,
            expectedPresence: $this->expectedPresence,
            mode: $this->mode,
            expectedColumns: $this->expectedColumns,
            setColumns: $this->setColumns,
        );
    }
}
