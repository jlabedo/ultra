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

    public function normalizeKey(MappedKey $key): MappedKey
    {
        $key->assertColumns($this->keyColumns);

        return $this->normalizeKeyValues($key);
    }

    public function normalizeOwner(MappedKey $owner): MappedKey
    {
        if ([] === $this->ownerColumns) {
            throw new \LogicException('This mapped row set has no declared owner.');
        }
        $owner->assertColumns($this->ownerColumns);

        return $this->normalizeKeyValues($owner);
    }

    private function normalizeKeyValues(MappedKey $key): MappedKey
    {
        $values = $key->values;
        foreach ($values as $name => $value) {
            $normalized = $this->normalizeValue($name, $value);
            assert(is_int($normalized) || is_string($normalized), 'Mapped keys have scalar representations.');
            $values[$name] = $normalized;
        }

        return $values === $key->values ? $key : new MappedKey($values);
    }

    public function ownerKey(MappedKey $key): MappedKey
    {
        return new MappedKey(array_intersect_key($key->values, $this->ownerColumnSet));
    }

    public function normalizeValue(string $name, mixed $value, bool $preserveTimestampOffset = false): mixed
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

        return $column->normalize($value, $preserveTimestampOffset);
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
            $mapping = $this->column($column);
            if (PostgresMappedColumnType::Jsonb === $mapping->codec) {
                $values[$column] = $mapping->normalize($value);
            }
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
