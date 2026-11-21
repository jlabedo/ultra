<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class MappedRowSetMapping
{
    /** @var array<string, MappedColumn> */
    public array $columns;

    /** @var list<string> */
    public array $keyColumns;

    /** @var list<string> */
    public array $ownerColumns;

    /**
     * @param array<array-key, mixed> $columns
     * @param list<string> $keyColumns
     * @param list<string> $ownerColumns Maps the owner's complete key to columns in this row set's key.
     */
    public function __construct(
        public string $table,
        array $columns,
        array $keyColumns,
        array $ownerColumns = [],
    ) {
        if ('' === $table) {
            throw new \InvalidArgumentException('A mapped table name cannot be empty.');
        }

        foreach ($columns as $name => $column) {
            if (!is_string($name) || '' === $name || !$column instanceof MappedColumn) {
                throw new \InvalidArgumentException('Mapped columns must have non-empty names and representations.');
            }
        }
        /** @var array<string, MappedColumn> $columns */
        $this->columns = $columns;

        if ([] === $keyColumns || count(array_unique($keyColumns)) !== count($keyColumns)) {
            throw new \InvalidArgumentException('A mapped key must have non-empty, distinct columns.');
        }
        foreach ($keyColumns as $name) {
            $column = $this->columns[$name] ?? null;
            if (null === $column || $column->nullable || !in_array($column->type, ['int', 'string'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Key column "%s" must be mapped as a non-null int or string.',
                    $name,
                ));
            }
        }
        if (
            [] !== array_diff($ownerColumns, $keyColumns)
            || count(array_unique($ownerColumns)) !== count($ownerColumns)
            || count($ownerColumns) >= count($keyColumns)
        ) {
            throw new \InvalidArgumentException(
                'Owner columns must be distinct and leave at least one item key column.',
            );
        }

        sort($keyColumns, SORT_STRING);
        sort($ownerColumns, SORT_STRING);
        $this->keyColumns = $keyColumns;
        $this->ownerColumns = $ownerColumns;
    }

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
        if (in_array($name, $this->keyColumns, true)) {
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
