<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * Immutable named scalar key used at mapped-storage boundaries.
 */
final readonly class MappedKey implements \Stringable
{
    /** @var array<string, int|string> */
    public array $values;

    /** @param array<array-key, mixed> $values Validated column names and scalar values are stored below. */
    public function __construct(array $values)
    {
        if ([] === $values) {
            throw new \InvalidArgumentException('A mapped key must contain at least one column.');
        }

        foreach ($values as $column => $value) {
            if (!is_string($column) || '' === $column) {
                throw new \InvalidArgumentException('Mapped key columns must be non-empty strings.');
            }

            if (!is_int($value) && !is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Mapped key column "%s" must be an int or string, got %s.',
                    $column,
                    get_debug_type($value),
                ));
            }
        }

        ksort($values, SORT_STRING);
        /** @var array<non-empty-string, int|string> $values */
        $this->values = $values;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return array_keys($this->values);
    }

    public function value(string $column): int|string
    {
        if (!array_key_exists($column, $this->values)) {
            throw new \InvalidArgumentException(sprintf('Mapped key has no column "%s".', $column));
        }

        return $this->values[$column];
    }

    public function contains(string $column): bool
    {
        return array_key_exists($column, $this->values);
    }

    /** @param list<string> $columns */
    public function assertColumns(array $columns): void
    {
        sort($columns, SORT_STRING);
        if ($this->columns() !== $columns) {
            throw new \InvalidArgumentException(sprintf(
                'Mapped key columns [%s] do not match expected columns [%s].',
                implode(', ', $this->columns()),
                implode(', ', $columns),
            ));
        }
    }

    public function mergedWith(self $other): self
    {
        $overlap = array_intersect_key($this->values, $other->values);
        foreach ($overlap as $column => $value) {
            if ($other->values[$column] !== $value) {
                throw new \InvalidArgumentException(sprintf('Mapped key column "%s" has conflicting values.', $column));
            }
        }

        return new self([...$this->values, ...$other->values]);
    }

    public function matches(self $partial): bool
    {
        foreach ($partial->values as $column => $value) {
            if (!array_key_exists($column, $this->values) || $this->values[$column] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function fingerprint(): string
    {
        return json_encode(
            array_map(static fn(int|string $value): string => (string) $value, $this->values),
            JSON_THROW_ON_ERROR,
        );
    }

    public function __toString(): string
    {
        return $this->fingerprint();
    }
}
