<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Buffer;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\Plan\RowUpdatePlan;

/**
 * Internal mutable state backing one fluent row update.
 *
 * @internal
 */
final class BufferedRowUpdate
{
    public ?string $expectedPresence = null;

    public ?string $mode = null;

    /** Local absence imposed by reconciliation or cancellation of an expected-absent row. */
    public bool $removedByReconciliation = false;

    /** @var array<string, mixed> */
    public array $expectedColumns = [];

    /** @var array<string, mixed> */
    public array $setColumns = [];

    public function __construct(
        public readonly MappedKey $key,
    ) {}

    public function hasPendingChanges(): bool
    {
        return null !== $this->expectedPresence || null !== $this->writeMode();
    }

    /** A write, a check-only plan, or null when nothing was registered. */
    public function toPlan(): ?RowUpdatePlan
    {
        $mode = $this->writeMode();
        if (null === $mode && null === $this->expectedPresence) {
            return null;
        }

        return new RowUpdatePlan(
            key: $this->key,
            expectedPresence: $this->expectedPresence,
            mode: $mode ?? RowUpdatePlan::MODE_EXPECT,
            expectedColumns: $this->expectedColumns,
            setColumns: null === $mode ? [] : $this->setColumns,
        );
    }

    private function writeMode(): ?string
    {
        if (RowUpdatePlan::MODE_UPDATE === $this->mode && $this->updatesOnlyExpectedValues()) {
            return null;
        }

        return $this->mode;
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
