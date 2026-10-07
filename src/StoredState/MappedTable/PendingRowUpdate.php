<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * Fluent handle used to configure the pending row change buffered by the table unit of work.
 */
final readonly class PendingRowUpdate
{
    public function __construct(
        private BufferedRowUpdate $update,
        private string $tableName = 'mapped row set',
    ) {}

    public function insert(): self
    {
        if (RowUpdatePlan::EXPECTED_PRESENT === $this->update->expectedPresence) {
            if ($this->update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $this->update->mode) {
                $this->update->mode = RowUpdatePlan::MODE_REPLACE;
                $this->update->removedByReconciliation = false;

                return $this;
            }

            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" for insert because the row is already expected to exist.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        $this->update->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;
        $this->update->mode = RowUpdatePlan::MODE_INSERT;
        $this->update->removedByReconciliation = false;

        return $this;
    }

    /**
     * Expects the row to be absent in this unit of work's view: a row deleted by this unit of work satisfies it
     * immediately, a row written by it fails immediately, otherwise the committed row must be absent at flush.
     */
    public function expectAbsent(): self
    {
        if ($this->update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $this->update->mode) {
            return $this;
        }

        if (true === $this->update->toPlan()?->writes()) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" as expected absent because it is written in this unit of work.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        if (RowUpdatePlan::EXPECTED_PRESENT === $this->update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" as expected absent because it is already expected to exist.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        $this->update->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;

        return $this;
    }

    /**
     * Expect presence in the unit-of-work view. Buffered writes establish it locally;
     * otherwise the committed row must exist at flush.
     */
    public function expectPresent(): self
    {
        if ($this->update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $this->update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot expect table "%s", row "%s" to be present because it is deleted in this unit of work.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }
        if (true === $this->update->toPlan()?->writes()) {
            return $this;
        }
        if (RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot expect table "%s", row "%s" to be present because it is already expected absent.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        $this->update->expectedPresence = RowUpdatePlan::EXPECTED_PRESENT;

        return $this;
    }

    public function upsert(): self
    {
        $this->update->removedByReconciliation = false;
        if (RowUpdatePlan::EXPECTED_PRESENT === $this->update->expectedPresence) {
            if (RowUpdatePlan::MODE_DELETE === $this->update->mode) {
                $this->update->mode = RowUpdatePlan::MODE_REPLACE;

                return $this;
            }

            $this->update->mode ??= RowUpdatePlan::MODE_UPDATE;

            return $this;
        }

        if (RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence) {
            $this->update->mode ??= RowUpdatePlan::MODE_INSERT;
            if (RowUpdatePlan::MODE_UPDATE === $this->update->mode) {
                $this->update->mode = RowUpdatePlan::MODE_INSERT;
            }

            return $this;
        }

        $this->update->mode = RowUpdatePlan::MODE_UPSERT;

        return $this;
    }

    public function delete(): self
    {
        if ($this->update->removedByReconciliation) {
            return $this;
        }
        if (RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence) {
            // Deleting an expected-absent row only checks absence, including a cancelled insertion.
            $this->update->mode = null;
            $this->update->expectedColumns = [];
            $this->update->setColumns = [];

            return $this;
        }

        $this->update->expectedPresence ??= RowUpdatePlan::EXPECTED_PRESENT;
        $this->update->mode = RowUpdatePlan::MODE_DELETE;
        $this->update->setColumns = [];

        return $this;
    }

    /**
     * Expects a column value in this unit of work's view, the one MappedRowSet::row() returns. A value written by
     * this unit of work is checked immediately; a value coming from storage becomes a condition checked at flush.
     */
    public function expectColumnValue(string $column, mixed $value): self
    {
        if ($this->update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $this->update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is deleted in this unit of work.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        $rowIsWritten =
            RowUpdatePlan::MODE_INSERT === $this->update->mode || RowUpdatePlan::MODE_REPLACE === $this->update->mode;
        if (array_key_exists($column, $this->update->setColumns) || $rowIsWritten) {
            // Inserted and replaced rows are entirely written by this unit of work: unset columns are null.
            if (($this->update->setColumns[$column] ?? null) !== $value) {
                throw new ConcurrencyException(sprintf(
                    'Expected value for table "%s", row "%s", column "%s" differs from the value written in this unit of work.',
                    $this->tableName,
                    (string) $this->update->key,
                    $column,
                ));
            }

            return $this;
        }

        if (RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is already expected to be absent.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        $this->update->expectedPresence = RowUpdatePlan::EXPECTED_PRESENT;
        if (RowUpdatePlan::MODE_UPSERT === $this->update->mode) {
            $this->update->mode = RowUpdatePlan::MODE_UPDATE;
        }

        if (
            array_key_exists($column, $this->update->expectedColumns)
            && $this->update->expectedColumns[$column] !== $value
        ) {
            throw new ConcurrencyException(sprintf(
                'Conflicting expected values were registered for table "%s", row "%s", column "%s".',
                $this->tableName,
                (string) $this->update->key,
                $column,
            ));
        }

        $this->update->expectedColumns[$column] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $expectedValues
     */
    public function expectColumnValues(array $expectedValues): self
    {
        foreach ($expectedValues as $column => $value) {
            $this->expectColumnValue($column, $value);
        }

        return $this;
    }

    public function setColumn(string $column, mixed $value): self
    {
        if ($this->update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $this->update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot register column updates for table "%s", row "%s" because it is already marked for delete.',
                $this->tableName,
                (string) $this->update->key,
            ));
        }

        if (null === $this->update->mode) {
            $this->update->mode = RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence
                ? RowUpdatePlan::MODE_INSERT
                : RowUpdatePlan::MODE_UPDATE;
        }

        $this->update->setColumns[$column] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setColumns(array $values): self
    {
        foreach ($values as $column => $value) {
            $this->setColumn($column, $value);
        }

        return $this;
    }
}
