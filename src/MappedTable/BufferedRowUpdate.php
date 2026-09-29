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

    /**
     * The row's plan: a write, a check-only plan (RowUpdatePlan::MODE_EXPECT) when the row is only expected or its
     * update writes only expected values, or null when nothing was registered.
     */
    public function toPlan(): ?RowUpdatePlan
    {
        $writes = null !== $this->mode && (RowUpdatePlan::MODE_UPDATE !== $this->mode || !$this->updatesOnlyExpectedValues());
        if (!$writes && null === $this->expectedPresence) {
            return null;
        }

        return new RowUpdatePlan(
            key: $this->key,
            expectedPresence: $this->expectedPresence,
            mode: $writes ? $this->mode : RowUpdatePlan::MODE_EXPECT,
            expectedColumns: $this->expectedColumns,
            setColumns: $writes ? $this->setColumns : [],
        );
    }

    /**
     * An update that sets every column to the value it expects changes nothing: it is only a check. This keeps
     * saving an unchanged state (or unchanged collection items) from rewriting rows one statement at a time.
     */
    private function updatesOnlyExpectedValues(): bool
    {
        foreach ($this->setColumns as $column => $value) {
            if (!array_key_exists($column, $this->expectedColumns) || $this->expectedColumns[$column] !== $value) {
                return false;
            }
        }

        return true;
    }
}
