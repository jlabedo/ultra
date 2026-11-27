<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Buffer;

use Ultra\MappedTable\Codec\IntegerCodec;
use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\Plan\RowUpdatePlan;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * Pending row changes and their composition rules.
 *
 * @internal
 */
final class BufferedRowUpdate
{
    private ?string $expectedPresence = null;

    private ?string $mode = null;

    /** @var array<string, mixed> */
    private array $expectedColumns = [];

    /** @var array<string, mixed> */
    private array $setColumns = [];

    /** @var array<string, int> */
    private array $increments = [];

    public function __construct(
        public readonly MappedKey $key,
        private readonly MappedRowSetMetadata $mapping,
        private bool $locallyAbsent = false,
    ) {}

    public function isDeleted(): bool
    {
        return $this->locallyAbsent || RowUpdatePlan::MODE_DELETE === $this->mode;
    }

    /** An explicit mutation can affect membership even when it reduces to a check at flush. */
    public function hasWriteIntent(): bool
    {
        return null !== $this->mode;
    }

    public function writes(): bool
    {
        return null !== $this->writeMode();
    }

    public function replacesStoredRow(): bool
    {
        return RowUpdatePlan::MODE_INSERT === $this->mode || RowUpdatePlan::MODE_REPLACE === $this->mode;
    }

    public function initializesIncrements(): bool
    {
        return $this->replacesStoredRow() || RowUpdatePlan::MODE_UPSERT === $this->mode;
    }

    /** @return array<string, mixed> */
    public function writtenColumns(): array
    {
        return $this->mapping->copyValues($this->setColumns);
    }

    /** @return array<string, int> */
    public function increments(): array
    {
        return $this->increments;
    }

    public function excludeFromReconciliation(): void
    {
        $this->mode = null;
        $this->setColumns = [];
        $this->increments = [];
        $this->locallyAbsent = true;
    }

    /**
     * Apply an observation to this candidate. Null represents absence, and an empty array observes presence alone.
     *
     * @param array<string, mixed>|null $storedValues
     * @param array<string, true>|null $observedColumns
     * @param array<string, true> $initializedColumns Relative values visible because no stored row exists.
     */
    public function applyObservation(?array $storedValues, ?array $observedColumns, array $initializedColumns): void
    {
        if (null === $storedValues) {
            $this->expectAbsent();

            return;
        }

        if (
            []
            !== (
                null === $observedColumns
                    ? $initializedColumns
                    : array_intersect_key($initializedColumns, $observedColumns)
            )
        ) {
            $this->insert();

            return;
        }

        $this->expectPresent();
        $values = null === $observedColumns ? $storedValues : array_intersect_key($storedValues, $observedColumns);
        foreach ($values as $column => $value) {
            $this->expectStoredColumnValue($column, $this->mapping->normalizeValue($column, $value));
        }
    }

    /** @param array<string, mixed> $values */
    public function expectColumnValues(array $values): void
    {
        foreach ($values as $column => $value) {
            $this->expectColumnValue($column, $value);
        }
    }

    public function insert(): void
    {
        if (RowUpdatePlan::EXPECTED_PRESENT === $this->expectedPresence) {
            if ($this->isDeleted()) {
                $this->mode = RowUpdatePlan::MODE_REPLACE;
                $this->locallyAbsent = false;

                return;
            }

            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" for insert because the row is already expected to exist.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        $this->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;
        $this->mode = RowUpdatePlan::MODE_INSERT;
        $this->locallyAbsent = false;
    }

    public function expectAbsent(): void
    {
        if ($this->isDeleted()) {
            return;
        }

        if ($this->writes()) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" as expected absent because it is written in this unit of work.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        if (RowUpdatePlan::EXPECTED_PRESENT === $this->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" as expected absent because it is already expected to exist.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        $this->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;
    }

    public function expectPresent(): void
    {
        if ($this->isDeleted()) {
            throw new ConcurrencyException(sprintf(
                'Cannot expect table "%s", row "%s" to be present because it is deleted in this unit of work.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }
        if ($this->writes()) {
            return;
        }
        if (RowUpdatePlan::EXPECTED_ABSENT === $this->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot expect table "%s", row "%s" to be present because it is already expected absent.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        $this->expectedPresence = RowUpdatePlan::EXPECTED_PRESENT;
    }

    public function upsert(): void
    {
        $this->locallyAbsent = false;
        if (RowUpdatePlan::EXPECTED_PRESENT === $this->expectedPresence) {
            if (RowUpdatePlan::MODE_DELETE === $this->mode) {
                $this->mode = RowUpdatePlan::MODE_REPLACE;

                return;
            }

            $this->mode ??= RowUpdatePlan::MODE_UPDATE;

            return;
        }

        if (RowUpdatePlan::EXPECTED_ABSENT === $this->expectedPresence) {
            $this->mode ??= RowUpdatePlan::MODE_INSERT;
            if (RowUpdatePlan::MODE_UPDATE === $this->mode) {
                $this->mode = RowUpdatePlan::MODE_INSERT;
            }

            return;
        }

        $this->mode = RowUpdatePlan::MODE_UPSERT;
    }

    public function delete(): void
    {
        if ($this->locallyAbsent) {
            return;
        }
        if (RowUpdatePlan::EXPECTED_ABSENT === $this->expectedPresence) {
            // Deleting an expected-absent row only checks absence, including a cancelled insertion.
            $this->mode = null;
            $this->locallyAbsent = true;
            $this->expectedColumns = [];
            $this->setColumns = [];
            $this->increments = [];

            return;
        }

        $this->expectedPresence ??= RowUpdatePlan::EXPECTED_PRESENT;
        $this->mode = RowUpdatePlan::MODE_DELETE;
        $this->setColumns = [];
        $this->increments = [];
    }

    public function expectColumnValue(string $column, mixed $value): void
    {
        $type = $this->mapping->column($column);
        $value = $this->mapping->normalizeValue($column, $value);
        if ($this->key->contains($column)) {
            if ($this->key->value($column) !== $type->key($value)) {
                throw new ConcurrencyException('Expected key column value differs from the mapped row key.');
            }

            $this->expectPresent();

            return;
        }

        if ($this->isDeleted()) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is deleted in this unit of work.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        if (array_key_exists($column, $this->setColumns) || $this->replacesStoredRow()) {
            $written = $this->setColumns[$column] ?? $this->increments[$column] ?? null;
            if (!$type->equivalent($written, $value)) {
                throw new ConcurrencyException(sprintf(
                    'Expected value for table "%s", row "%s", column "%s" differs from the value written in this unit of work.',
                    $this->mapping->table,
                    (string) $this->key,
                    $column,
                ));
            }

            return;
        }

        $delta = $this->increments[$column] ?? null;
        if (null !== $delta) {
            if (
                !is_int($value)
                || $delta > 0 && $value < (PHP_INT_MIN + $delta)
                || $delta < 0 && $value > (PHP_INT_MAX + $delta)
            ) {
                throw new ConcurrencyException('The expected incremented value has no integer base value.');
            }
            $value = $this->mapping->normalizeValue($column, $value - $delta);
        }

        $this->expectStoredColumnValue($column, $value);
    }

    private function expectStoredColumnValue(string $column, mixed $value): void
    {
        if (RowUpdatePlan::EXPECTED_ABSENT === $this->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is already expected to be absent.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        $this->expectedPresence = RowUpdatePlan::EXPECTED_PRESENT;
        if (RowUpdatePlan::MODE_UPSERT === $this->mode) {
            $this->mode = RowUpdatePlan::MODE_UPDATE;
        }

        if (
            array_key_exists($column, $this->expectedColumns)
            && !$this->mapping->column($column)->equivalent($this->expectedColumns[$column], $value)
        ) {
            throw new ConcurrencyException(sprintf(
                'Conflicting expected values were registered for table "%s", row "%s", column "%s".',
                $this->mapping->table,
                (string) $this->key,
                $column,
            ));
        }

        $this->expectedColumns[$column] ??= $value;
    }

    public function setColumn(string $column, mixed $value): void
    {
        $value = $this->mapping->normalizeWritableValue($column, $value);
        $this->prepareColumnWrite();
        $this->setColumns[$column] = $value;
        unset($this->increments[$column]);
    }

    public function incrementColumn(string $column, int $delta): void
    {
        $type = $this->mapping->incrementableColumns[$column] ?? null;
        if (null === $type) {
            $this->mapping->column($column);
            throw new \InvalidArgumentException(sprintf(
                'Increment requires a non-null mapped integer column outside the key, got "%s.%s".',
                $this->mapping->table,
                $column,
            ));
        }
        if (isset($this->setColumns[$column])) {
            $this->setColumn($column, $type->increment($this->setColumns[$column], $delta));

            return;
        }

        $sum = IntegerCodec::add($this->increments[$column] ?? 0, $delta);
        $this->prepareColumnWrite();
        $this->increments[$column] = $sum;
    }

    private function prepareColumnWrite(): void
    {
        if ($this->isDeleted()) {
            throw new ConcurrencyException(sprintf(
                'Cannot register column updates for table "%s", row "%s" because it is already marked for delete.',
                $this->mapping->table,
                (string) $this->key,
            ));
        }

        if (null === $this->mode) {
            $this->mode = RowUpdatePlan::EXPECTED_ABSENT === $this->expectedPresence
                ? RowUpdatePlan::MODE_INSERT
                : RowUpdatePlan::MODE_UPDATE;
        }
    }

    /** @param array<string, mixed> $values */
    public function setColumns(array $values): void
    {
        foreach ($values as $column => $value) {
            $this->setColumn($column, $value);
        }
    }

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

        $expected = $this->mapping->copyValues($this->expectedColumns);
        $values = null === $mode ? [] : $this->writtenColumns();
        $increments = $this->increments;
        if ([] !== $increments) {
            ksort($expected, SORT_STRING);
            ksort($values, SORT_STRING);
            ksort($increments, SORT_STRING);
        }

        return new RowUpdatePlan(
            key: $this->key,
            expectedPresence: $this->expectedPresence,
            mode: $mode ?? RowUpdatePlan::MODE_EXPECT,
            expectedColumns: $expected,
            setColumns: $values,
            increments: $increments,
        );
    }

    private function writeMode(): ?string
    {
        if (
            RowUpdatePlan::MODE_UPDATE === $this->mode
            && [] === $this->increments
            && $this->updatesOnlyExpectedValues()
        ) {
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
            if (
                !array_key_exists($column, $this->expectedColumns)
                || !$this->mapping->column($column)->equivalent($this->expectedColumns[$column], $value)
            ) {
                return false;
            }
        }

        return true;
    }
}
