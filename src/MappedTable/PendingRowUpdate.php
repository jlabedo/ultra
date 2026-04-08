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
        private string $tableName,
    ) {}

    public function insert(): self
    {
        if (RowUpdatePlan::EXPECTED_PRESENT === $this->update->expectedPresence) {
            if (RowUpdatePlan::MODE_DELETE === $this->update->mode) {
                $this->update->mode = RowUpdatePlan::MODE_REPLACE;

                return $this;
            }

            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" for insert because the row is already expected to exist.',
                $this->tableName,
                (string) $this->update->id,
            ));
        }

        $this->update->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;
        $this->update->mode = RowUpdatePlan::MODE_INSERT;

        return $this;
    }

    public function upsert(): self
    {
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
        if (
            RowUpdatePlan::MODE_INSERT === $this->update->mode
            && RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence
        ) {
            // Inserting and then deleting the same row before flush is a no-op.
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

    public function expectColumnValue(string $column, mixed $value): self
    {
        if (RowUpdatePlan::EXPECTED_ABSENT === $this->update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is already marked for insert.',
                $this->tableName,
                (string) $this->update->id,
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
                (string) $this->update->id,
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
        if (RowUpdatePlan::MODE_DELETE === $this->update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot register column updates for table "%s", row "%s" because it is already marked for delete.',
                $this->tableName,
                (string) $this->update->id,
            ));
        }

        $this->update->mode ??= RowUpdatePlan::MODE_UPDATE;

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
