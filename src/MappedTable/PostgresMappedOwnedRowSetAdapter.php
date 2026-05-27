<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;

final class PostgresMappedOwnedRowSetAdapter implements MappedOwnedRowSetAdapter
{
    /** @var list<string> */
    private readonly array $parentColumns;

    /**
     * @param list<string>                            $parentColumns Names of the columns that form the parent key.
     * @param list<string>                            $rowColumns    Names of the detail-row columns (in deterministic order).
     * @param array<string, PostgresMappedColumnType> $columnTypes   Optional encoders/SQL casts keyed by column name.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
        array $parentColumns,
        private readonly array $rowColumns,
        private readonly array $columnTypes = [],
    ) {
        if ([] === $parentColumns) {
            throw new \InvalidArgumentException('Parent columns must contain at least one column.');
        }

        if ([] === $rowColumns) {
            throw new \InvalidArgumentException('Row columns must contain at least one column.');
        }

        $this->parentColumns = array_values($parentColumns);
    }

    public function rowsForParent(array $parentKey): array
    {
        $this->assertParentKeyMatches($parentKey);

        $orderedValues = $this->orderedParentValues($parentKey);
        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            $this->rowColumns,
        );
        $orderColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            $this->rowColumns,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM %s WHERE %s ORDER BY %s',
                implode(', ', $quotedColumns),
                $this->connection->quoteSingleIdentifier($this->tableName),
                $this->parentWhereSql(),
                implode(', ', $orderColumns),
            ),
            $orderedValues,
        );

        return array_map($this->decodeRow(...), $rows);
    }

    public function apply(OwnedRowSetUpdatePlan $plan): void
    {
        foreach ($plan->parentMatchDeletes as $delete) {
            $this->applyParentMatchDelete($delete);
        }

        foreach ($plan->replacements as $replacement) {
            $this->assertParentKeyMatches($replacement->parentKey);

            $this->connection->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE %s',
                    $this->connection->quoteSingleIdentifier($this->tableName),
                    $this->parentWhereSql(),
                ),
                $this->orderedParentValues($replacement->parentKey),
            );

            if ([] === $replacement->rows) {
                continue;
            }

            $this->bulkInsert($replacement);
        }
    }

    private function applyParentMatchDelete(OwnedSetParentMatchDelete $delete): void
    {
        $this->assertPartialParentKey($delete->partialParentKey);

        $columns = array_keys($delete->partialParentKey);
        $whereSql = implode(' AND ', array_map(
            fn (string $column): string => sprintf(
                '%s = %s',
                $this->connection->quoteSingleIdentifier($column),
                $this->placeholder($column),
            ),
            $columns,
        ));

        $params = [];
        foreach ($columns as $column) {
            $params[] = $this->encodeColumnValue($column, $delete->partialParentKey[$column]);
        }

        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE %s',
                $this->connection->quoteSingleIdentifier($this->tableName),
                $whereSql,
            ),
            $params,
        );
    }

    private function bulkInsert(OwnedSetReplace $replacement): void
    {
        $columns = [...$this->parentColumns, ...$this->rowColumns];
        $quotedColumns = array_map($this->connection->quoteSingleIdentifier(...), $columns);
        $rowPlaceholder = '('.implode(', ', array_map($this->placeholder(...), $columns)).')';

        $params = [];
        $parentValues = $this->orderedParentValues($replacement->parentKey);

        foreach ($replacement->rows as $row) {
            foreach ($parentValues as $value) {
                $params[] = $value;
            }

            foreach ($this->rowColumns as $column) {
                $params[] = $this->encodeColumnValue($column, $row[$column] ?? null);
            }
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_fill(0, count($replacement->rows), $rowPlaceholder)),
            ),
            $params,
        );
    }

    /**
     * @param array<string, int|string> $parentKey
     */
    private function assertParentKeyMatches(array $parentKey): void
    {
        if (count($parentKey) !== count($this->parentColumns)) {
            throw new \InvalidArgumentException(sprintf(
                'Parent key for table "%s" expects columns [%s].',
                $this->tableName,
                implode(', ', $this->parentColumns),
            ));
        }

        foreach ($this->parentColumns as $column) {
            if (!array_key_exists($column, $parentKey)) {
                throw new \InvalidArgumentException(sprintf(
                    'Parent key for table "%s" is missing column "%s".',
                    $this->tableName,
                    $column,
                ));
            }
        }
    }

    /**
     * @param array<string, int|string> $partial
     */
    private function assertPartialParentKey(array $partial): void
    {
        if ([] === $partial) {
            throw new \InvalidArgumentException(sprintf(
                'Partial parent key for table "%s" must contain at least one column.',
                $this->tableName,
            ));
        }

        foreach ($partial as $column => $_) {
            if (!in_array($column, $this->parentColumns, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Column "%s" is not part of the parent key for table "%s" (expected one of: %s).',
                    $column,
                    $this->tableName,
                    implode(', ', $this->parentColumns),
                ));
            }
        }
    }

    /**
     * @param array<string, int|string> $parentKey
     *
     * @return list<int|string>
     */
    private function orderedParentValues(array $parentKey): array
    {
        $values = [];
        foreach ($this->parentColumns as $column) {
            $values[] = $parentKey[$column];
        }

        return $values;
    }

    private function parentWhereSql(): string
    {
        return implode(' AND ', array_map(
            fn (string $column): string => sprintf(
                '%s = %s',
                $this->connection->quoteSingleIdentifier($column),
                $this->placeholder($column),
            ),
            $this->parentColumns,
        ));
    }

    private function placeholder(string $column): string
    {
        $type = $this->columnTypes[$column] ?? null;

        return null === $type ? '?' : sprintf('?::%s', $type->sqlType);
    }

    private function encodeColumnValue(string $column, mixed $value): mixed
    {
        return ($this->columnTypes[$column] ?? null)?->encode($value) ?? $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        foreach ($this->columnTypes as $column => $type) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $type->decode($row[$column]);
            }
        }

        return $row;
    }
}
