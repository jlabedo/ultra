<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** @internal Immutable metadata validated by the row set definition. */
readonly class MappedRowSetMetadata
{
    /**
     * @param array<string, MappedColumn> $columns
     * @param list<string> $keyColumns Canonical column order.
     * @param list<string> $ownerColumns Canonical column order.
     * @param list<string> $columnNames
     * @param list<string> $nonKeyColumns
     * @param array<string, true> $keyColumnSet
     * @param array<string, true> $ownerColumnSet
     */
    public function __construct(
        public string $table,
        public array $columns,
        public array $keyColumns,
        public array $ownerColumns,
        public ?MappedRowSetOwner $owner,
        public array $columnNames,
        public array $nonKeyColumns,
        public array $keyColumnSet,
        public array $ownerColumnSet,
    ) {}

    public function assertKey(MappedKey $key): void
    {
        $key->assertColumns($this->keyColumns);
        $this->assertValues($key->values);
    }

    public function assertOwner(MappedKey $owner): void
    {
        if ([] === $this->ownerColumns) {
            throw new \LogicException('This mapped row set has no declared owner.');
        }

        $owner->assertColumns($this->ownerColumns);
        $this->assertValues($owner->values);
    }

    public function ownerKey(MappedKey $key): MappedKey
    {
        return new MappedKey(array_intersect_key($key->values, $this->ownerColumnSet));
    }

    /** @param array<string, mixed> $values */
    public function assertValues(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->assertValue($name, $value);
        }
    }

    public function assertValue(string $name, mixed $value): void
    {
        $column = $this->column($name);
        if (!$column->accepts($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Column "%s" in mapped table "%s" expects %s%s, got %s.',
                $name,
                $this->table,
                $column->type,
                $column->nullable ? ' or null' : '',
                get_debug_type($value),
            ));
        }
    }

    public function assertWritableValue(string $name, mixed $value): void
    {
        if (isset($this->keyColumnSet[$name])) {
            throw new \InvalidArgumentException(sprintf('Mapped key column "%s" cannot be written.', $name));
        }

        $this->assertValue($name, $value);
    }

    public function column(string $name): MappedColumn
    {
        return (
            $this->columns[$name] ?? throw new \InvalidArgumentException(sprintf(
                'Unknown column "%s" in mapped table "%s".',
                $name,
                $this->table,
            ))
        );
    }
}
