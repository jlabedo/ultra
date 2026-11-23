<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Buffer\BufferedRowUpdate;

/**
 * Fluent handle for reading, observing and buffering changes to one mapped row.
 */
final readonly class MappedRowHandle
{
    /** @internal Obtain through MappedRowSet::withKey(). */
    public function __construct(
        private MappedRowSet $rowSet,
        private MappedKey $key,
        private int $generation,
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
        return $this->rowSet->readForHandle($this->key, $this->generation, $columns);
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
        return $this->rowSet->readForHandle(
            $this->key,
            $this->generation,
            $columns,
            observe: true,
            observedColumns: $observedColumns,
        );
    }

    private function update(): BufferedRowUpdate
    {
        return $this->rowSet->updateForHandle($this->key, $this->generation);
    }

    public function insert(): self
    {
        $this->update()->insert();

        return $this;
    }

    /**
     * Expects the row to be absent in this unit of work's view: a row deleted by this unit of work satisfies it
     * immediately, a row written by it fails immediately, otherwise the committed row must be absent at flush.
     */
    public function expectAbsent(): self
    {
        $this->update()->expectAbsent();

        return $this;
    }

    /**
     * Expect presence in the unit-of-work view. Buffered writes establish it locally;
     * otherwise the committed row must exist at flush.
     */
    public function expectPresent(): self
    {
        $this->update()->expectPresent();

        return $this;
    }

    public function upsert(): self
    {
        $this->update()->upsert();

        return $this;
    }

    public function delete(): self
    {
        $this->update()->delete();

        return $this;
    }

    /**
     * Expects a column value in this unit of work's view. Local values are checked immediately. Values from
     * storage become conditions checked at flush.
     */
    public function expectColumnValue(string $column, mixed $value): self
    {
        $this->update()->expectColumnValue($column, $value);

        return $this;
    }

    /**
     * @param array<string, mixed> $expectedValues
     */
    public function expectColumnValues(array $expectedValues): self
    {
        if ([] !== $expectedValues) {
            $this->update()->expectColumnValues($expectedValues);
        }

        return $this;
    }

    public function setColumn(string $column, mixed $value): self
    {
        $this->update()->setColumn($column, $value);

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setColumns(array $values): self
    {
        if ([] !== $values) {
            $this->update()->setColumns($values);
        }

        return $this;
    }
}
