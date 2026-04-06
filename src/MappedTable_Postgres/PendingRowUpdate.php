<?php

declare(strict_types=1);

namespace Ultra\MappedTable_Postgres;

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
        if ([] !== $this->update->expectedColumns) {
            throw new ConcurrencyException(sprintf(
                'Cannot mark table "%s", row "%s" for insert after expected column values were registered.',
                $this->tableName,
                (string) $this->update->id,
            ));
        }

        $this->update->insert = true;

        return $this;
    }

    public function expectColumnValue(string $column, mixed $value): self
    {
        if ($this->update->insert) {
            throw new ConcurrencyException(sprintf(
                'Cannot register expected column values for table "%s", row "%s" because it is already marked for insert.',
                $this->tableName,
                (string) $this->update->id,
            ));
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
