<?php

declare(strict_types=1);

namespace Ultra\MappedTable_Postgres;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

/**
 * Fluent handle used to configure the optimistic preconditions and column updates for one row.
 */
final readonly class PendingRowUpdate
{
    public function __construct(
        private BufferedRowUpdate $update,
        private string $tableName,
    ) {}

    public function expectColumnValue(string $column, mixed $value): self
    {
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
