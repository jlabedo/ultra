<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final class InMemoryRowDatabase
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private array $ownedTables = [];

    /** @var list<array{tables: array<string, array<string, array<string, mixed>>>, ownedTables: array<string, array<string, list<array<string, mixed>>>>}> */
    private array $transactionSnapshots = [];

    /**
     * @param array<string, mixed> $row
     */
    public function seedRow(string $tableName, string $idColumn, array $row): void
    {
        if (!array_key_exists($idColumn, $row)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot seed table "%s" without id column "%s".',
                $tableName,
                $idColumn,
            ));
        }

        $this->tables[$tableName][$this->rowKey($row[$idColumn])] = $row;
    }

    public function beginTransaction(): void
    {
        $this->transactionSnapshots[] = [
            'tables' => $this->tables,
            'ownedTables' => $this->ownedTables,
        ];
    }

    public function commit(): void
    {
        if (!$this->isTransactionActive()) {
            throw new \LogicException('Cannot commit because no transaction is active.');
        }

        array_pop($this->transactionSnapshots);
    }

    public function rollBack(): void
    {
        $snapshot = array_pop($this->transactionSnapshots);
        if (null === $snapshot) {
            throw new \LogicException('Cannot roll back because no transaction is active.');
        }

        $this->tables = $snapshot['tables'];
        $this->ownedTables = $snapshot['ownedTables'];
    }

    /**
     * @param array<string, int|string> $parentKey
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForParent(string $tableName, array $parentKey): array
    {
        return $this->ownedTables[$tableName][self::parentBucketKey($parentKey)] ?? [];
    }

    /**
     * @param array<string, int|string>  $parentKey
     * @param list<array<string, mixed>> $rows
     */
    public function replaceOwnedSet(string $tableName, array $parentKey, array $rows): void
    {
        $bucket = self::parentBucketKey($parentKey);

        if ([] === $rows) {
            unset($this->ownedTables[$tableName][$bucket]);

            if (isset($this->ownedTables[$tableName]) && [] === $this->ownedTables[$tableName]) {
                unset($this->ownedTables[$tableName]);
            }

            return;
        }

        $storedRows = [];
        foreach ($rows as $row) {
            $storedRows[] = [...$parentKey, ...$row];
        }

        $this->ownedTables[$tableName][$bucket] = $storedRows;
    }

    /**
     * @param array<string, int|string> $parentKey
     */
    private static function parentBucketKey(array $parentKey): string
    {
        ksort($parentKey, SORT_STRING);

        return json_encode($parentKey, JSON_THROW_ON_ERROR);
    }

    public function isTransactionActive(): bool
    {
        return [] !== $this->transactionSnapshots;
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    /**
     * @param list<string> $columns
     * @param array<string, int|string> $scopeColumns
     */
    public function row(string $tableName, string $idColumn, int|string $id, array $columns = [], array $scopeColumns = []): array|false
    {
        $row = $this->findRow($tableName, $idColumn, $id, $scopeColumns);
        if (null === $row) {
            return false;
        }

        if (!$this->matchesScope($row, $scopeColumns)) {
            return false;
        }

        if ([] === $columns) {
            return $row;
        }

        $projectedRow = [];

        foreach ($columns as $column) {
            $projectedRow[$column] = $row[$column] ?? null;
        }

        return $projectedRow;
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * @param list<string> $columns
     * @param array<string, int|string> $scopeColumns
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(string $tableName, array $columns = [], array $scopeColumns = []): array
    {
        $rows = array_filter(
            $this->tables[$tableName] ?? [],
            static fn (array $row): bool => self::matchesScope($row, $scopeColumns),
        );
        ksort($rows, SORT_STRING);

        if ([] === $columns) {
            return $rows;
        }

        $projectedRows = [];

        foreach ($rows as $rowKey => $row) {
            $projectedRows[$rowKey] = [];

            foreach ($columns as $column) {
                $projectedRows[$rowKey][$column] = $row[$column] ?? null;
            }
        }

        return $projectedRows;
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * @param array<string, int|string> $scopeColumns
     */
    public function writeRow(string $tableName, string $idColumn, array $row, array $scopeColumns = []): void
    {
        if (!array_key_exists($idColumn, $row)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot write table "%s" row without id column "%s".',
                $tableName,
                $idColumn,
            ));
        }

        $this->deleteRow($tableName, $idColumn, $row[$idColumn], $scopeColumns);
        $this->tables[$tableName][$this->storageKey($row[$idColumn], $scopeColumns)] = $row;
    }

    /**
     * @param array<string, int|string> $scopeColumns
     */
    public function deleteRow(string $tableName, string $idColumn, int|string $id, array $scopeColumns = []): void
    {
        foreach ($this->tables[$tableName] ?? [] as $rowKey => $row) {
            if (($row[$idColumn] ?? null) === $id && self::matchesScope($row, $scopeColumns)) {
                unset($this->tables[$tableName][$rowKey]);
            }
        }
    }

    private function rowKey(int|string $id): string
    {
        return (string) $id;
    }

    /**
     * @param array<string, int|string> $scopeColumns
     */
    private function storageKey(int|string $id, array $scopeColumns): string
    {
        if ([] === $scopeColumns) {
            return $this->rowKey($id);
        }

        ksort($scopeColumns);

        return json_encode($scopeColumns, JSON_THROW_ON_ERROR).'#'.$this->rowKey($id);
    }

    /**
     * @param array<string, int|string> $scopeColumns
     *
     * @return array<string, mixed>|null
     */
    private function findRow(string $tableName, string $idColumn, int|string $id, array $scopeColumns): ?array
    {
        if ([] === $scopeColumns) {
            return $this->tables[$tableName][$this->rowKey($id)] ?? null;
        }

        foreach ($this->tables[$tableName] ?? [] as $row) {
            if (($row[$idColumn] ?? null) === $id && self::matchesScope($row, $scopeColumns)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int|string> $scopeColumns
     */
    private static function matchesScope(array $row, array $scopeColumns): bool
    {
        foreach ($scopeColumns as $column => $value) {
            if (($row[$column] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
