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

    /** @param array<array-key, mixed>|MappedKey $values */
    public function key(array|MappedKey $values): MappedKey
    {
        return $this->canonicalKey($values, $this->keyColumns);
    }

    /** @param array<array-key, mixed>|MappedKey $values */
    public function owner(array|MappedKey $values): MappedKey
    {
        if ([] === $this->ownerColumns) {
            throw new \LogicException('This mapped row set has no declared owner.');
        }

        return $this->canonicalKey($values, $this->ownerColumns);
    }

    /**
     * @param array<array-key, mixed>|MappedKey $values
     * @param list<string> $columns
     */
    private function canonicalKey(array|MappedKey $values, array $columns): MappedKey
    {
        $key = [];
        $input = $values instanceof MappedKey ? $values->values : $values;
        foreach ($input as $name => $value) {
            if (!is_string($name) || !in_array($name, $columns, true)) {
                throw new \InvalidArgumentException('Mapped key columns must match the declared key.');
            }
            $key[$name] = $this->column($name)->key($value);
        }
        $mapped = new MappedKey($key);
        $mapped->assertColumns($columns);

        return $values instanceof MappedKey && $values->values === $mapped->values ? $values : $mapped;
    }

    public function ownerKey(MappedKey $key): MappedKey
    {
        return new MappedKey(array_intersect_key($key->values, $this->ownerColumnSet));
    }

    public function normalizeValue(string $name, mixed $value): mixed
    {
        $column = $this->column($name);
        try {
            return $column->normalize($value);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Column "%s" in mapped table "%s" expects %s%s, got %s. %s',
                    $name,
                    $this->table,
                    $column->type ?? 'a scalar value',
                    false !== $column->nullable ? ' or null' : '',
                    get_debug_type($value),
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }
    }

    public function normalizeWritableValue(string $name, mixed $value): mixed
    {
        if (isset($this->keyColumnSet[$name])) {
            throw new \InvalidArgumentException(sprintf('Mapped key column "%s" cannot be written.', $name));
        }

        return $this->normalizeValue($name, $value);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function copyValues(array $values): array
    {
        foreach ($values as $column => $value) {
            $values[$column] = $this->column($column)->copy($value);
        }

        return $values;
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
