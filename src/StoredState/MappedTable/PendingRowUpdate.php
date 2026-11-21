<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * Fluent handle for reading, observing and buffering changes to one mapped row.
 */
final readonly class PendingRowUpdate
{
    /** @internal Obtain through MappedRowSet::withKey(). */
    public function __construct(
        private MappedRowSet $rowSet,
        private MappedKey $key,
        private int $generation,
        private string $tableName,
        private ?MappedRowSetMetadata $mapping = null,
    ) {}

    /**
     * Reads this unit of work's view. A null projection selects all columns, and an empty list selects only the key.
     * Unknown values are omitted from the result.
     *
     * @param list<string>|null $columns
     * @return array<string, mixed>|null
     */
    public function get(?array $columns = null): ?array
    {
        return $this->rowSet->readForHandle($this->key, $this->generation, $columns)['values'] ?? null;
    }

    /**
     * Reads the same projection as get(). Observes presence or absence and the returned non-key values from storage.
     *
     * @param list<string>|null $columns
     * @return array<string, mixed>|null
     */
    public function observe(?array $columns = null): ?array
    {
        return $this->observeProjectedColumns($columns, null);
    }

    /**
     * @internal Allows object views to hydrate additional columns outside their optimistic mapping.
     * @param list<string>|null $columns
     * @param array<string, true>|null $observedColumns
     * @return array<string, mixed>|null
     */
    public function observeProjectedColumns(?array $columns, ?array $observedColumns): ?array
    {
        $row = $this->rowSet->readForHandle($this->key, $this->generation, $columns, trackStorage: true);
        if (null === $row) {
            $this->expectAbsent();

            return null;
        }

        $values = null === $observedColumns
            ? $row['storedValues']
            : array_intersect_key($row['storedValues'], $observedColumns);
        if ([] === $values) {
            $this->expectPresent();

            return $row['values'];
        }

        $update = $this->update();
        $observation = clone $update;
        $this->expectPresence($observation);
        foreach ($values as $column => $value) {
            $this->expectValue($observation, $column, $value);
        }

        $update->expectedPresence = $observation->expectedPresence;
        $update->expectedColumns = $observation->expectedColumns;
        $update->mode = $observation->mode;

        return $row['values'];
    }

    private function update(): BufferedRowUpdate
    {
        return $this->rowSet->updateForHandle($this->key, $this->generation);
    }

    public function insert(): self
    {
        $update = $this->update();
        if (RowUpdatePlan::EXPECTED_PRESENT === $update->expectedPresence) {
            if ($update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $update->mode) {
                $update->mode = RowUpdatePlan::MODE_REPLACE;
                $update->removedByReconciliation = false;

                return $this;
            }

            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" for insert because the row is already expected to exist.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        $update->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;
        $update->mode = RowUpdatePlan::MODE_INSERT;
        $update->removedByReconciliation = false;

        return $this;
    }

    /**
     * Expects the row to be absent in this unit of work's view: a row deleted by this unit of work satisfies it
     * immediately, a row written by it fails immediately, otherwise the committed row must be absent at flush.
     */
    public function expectAbsent(): self
    {
        $update = $this->update();
        if ($update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $update->mode) {
            return $this;
        }

        if (true === $update->toPlan()?->writes()) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" as expected absent because it is written in this unit of work.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        if (RowUpdatePlan::EXPECTED_PRESENT === $update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" as expected absent because it is already expected to exist.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        $update->expectedPresence = RowUpdatePlan::EXPECTED_ABSENT;

        return $this;
    }

    /**
     * Expect presence in the unit-of-work view. Buffered writes establish it locally;
     * otherwise the committed row must exist at flush.
     */
    public function expectPresent(): self
    {
        $this->expectPresence($this->update());

        return $this;
    }

    private function expectPresence(BufferedRowUpdate $update): void
    {
        if ($update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot expect table "%s", row "%s" to be present because it is deleted in this unit of work.',
                $this->tableName,
                (string) $update->key,
            ));
        }
        if (true === $update->toPlan()?->writes()) {
            return;
        }
        if (RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot expect table "%s", row "%s" to be present because it is already expected absent.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        $update->expectedPresence = RowUpdatePlan::EXPECTED_PRESENT;
    }

    public function upsert(): self
    {
        $update = $this->update();
        $update->removedByReconciliation = false;
        if (RowUpdatePlan::EXPECTED_PRESENT === $update->expectedPresence) {
            if (RowUpdatePlan::MODE_DELETE === $update->mode) {
                $update->mode = RowUpdatePlan::MODE_REPLACE;

                return $this;
            }

            $update->mode ??= RowUpdatePlan::MODE_UPDATE;

            return $this;
        }

        if (RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
            $update->mode ??= RowUpdatePlan::MODE_INSERT;
            if (RowUpdatePlan::MODE_UPDATE === $update->mode) {
                $update->mode = RowUpdatePlan::MODE_INSERT;
            }

            return $this;
        }

        $update->mode = RowUpdatePlan::MODE_UPSERT;

        return $this;
    }

    public function delete(): self
    {
        $update = $this->update();
        if ($update->removedByReconciliation) {
            return $this;
        }
        if (RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
            // Deleting an expected-absent row only checks absence, including a cancelled insertion.
            $update->mode = null;
            $update->removedByReconciliation = true;
            $update->expectedColumns = [];
            $update->setColumns = [];

            return $this;
        }

        $update->expectedPresence ??= RowUpdatePlan::EXPECTED_PRESENT;
        $update->mode = RowUpdatePlan::MODE_DELETE;
        $update->setColumns = [];

        return $this;
    }

    /**
     * Expects a column value in this unit of work's view. Local values are checked immediately. Values from
     * storage become conditions checked at flush.
     */
    public function expectColumnValue(string $column, mixed $value): self
    {
        $this->expectValue($this->update(), $column, $value);

        return $this;
    }

    private function expectValue(BufferedRowUpdate $update, string $column, mixed $value): void
    {
        $this->mapping?->assertValue($column, $value);
        if (null !== $this->mapping && $update->key->contains($column)) {
            if ($update->key->value($column) !== $value) {
                throw new ConcurrencyException('Expected key column value differs from the mapped row key.');
            }

            $this->expectPresence($update);

            return;
        }

        if ($update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is deleted in this unit of work.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        $rowIsWritten = RowUpdatePlan::MODE_INSERT === $update->mode || RowUpdatePlan::MODE_REPLACE === $update->mode;
        if (array_key_exists($column, $update->setColumns) || $rowIsWritten) {
            // Inserted and replaced rows are entirely written by this unit of work: unset columns are null.
            if (($update->setColumns[$column] ?? null) !== $value) {
                throw new ConcurrencyException(sprintf(
                    'Expected value for table "%s", row "%s", column "%s" differs from the value written in this unit of work.',
                    $this->tableName,
                    (string) $update->key,
                    $column,
                ));
            }

            return;
        }

        if (RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is already expected to be absent.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        $update->expectedPresence = RowUpdatePlan::EXPECTED_PRESENT;
        if (RowUpdatePlan::MODE_UPSERT === $update->mode) {
            $update->mode = RowUpdatePlan::MODE_UPDATE;
        }

        if (array_key_exists($column, $update->expectedColumns) && $update->expectedColumns[$column] !== $value) {
            throw new ConcurrencyException(sprintf(
                'Conflicting expected values were registered for table "%s", row "%s", column "%s".',
                $this->tableName,
                (string) $update->key,
                $column,
            ));
        }

        $update->expectedColumns[$column] = $value;
    }

    /**
     * @param array<string, mixed> $expectedValues
     */
    public function expectColumnValues(array $expectedValues): self
    {
        if ([] !== $expectedValues) {
            $update = $this->update();
            foreach ($expectedValues as $column => $value) {
                $this->expectValue($update, $column, $value);
            }
        }

        return $this;
    }

    public function setColumn(string $column, mixed $value): self
    {
        $this->mapping?->assertWritableValue($column, $value);
        $update = $this->update();
        if ($update->removedByReconciliation || RowUpdatePlan::MODE_DELETE === $update->mode) {
            throw new ConcurrencyException(sprintf(
                'Cannot register column updates for table "%s", row "%s" because it is already marked for delete.',
                $this->tableName,
                (string) $update->key,
            ));
        }

        if (null === $update->mode) {
            $update->mode = RowUpdatePlan::EXPECTED_ABSENT === $update->expectedPresence
                ? RowUpdatePlan::MODE_INSERT
                : RowUpdatePlan::MODE_UPDATE;
        }

        $update->setColumns[$column] = $value;

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
