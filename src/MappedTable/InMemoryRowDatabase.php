<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * In-memory backing store shared by `InMemoryMappedRowSetAdapter` and
 * `InMemoryMappedOwnedRowSetAdapter`.
 *
 * Storage is a single list of rows per table — the same shape Postgres has.
 * The two adapter views over it (PK-keyed rows for `MappedRowSet`, parent-
 * keyed sets for `MappedOwnedRowSet`) just filter that list by different
 * column predicates. Transaction snapshots are correspondingly one-shot:
 * snapshot the table map, restore on rollback.
 */
final class InMemoryRowDatabase
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];

    /** @var list<array<string, list<array<string, mixed>>>> */
    private array $transactionSnapshots = [];

    /**
     * Idempotent insert by id (used to seed tests). If a row with the same
     * `$idColumn` value already exists in the table it is replaced.
     *
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

        $this->removeWhere($tableName, static fn (array $r): bool => ($r[$idColumn] ?? null) === $row[$idColumn]);
        $this->tables[$tableName][] = $row;
    }

    public function beginTransaction(): void
    {
        $this->transactionSnapshots[] = $this->tables;
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

        $this->tables = $snapshot;
    }

    public function isTransactionActive(): bool
    {
        return [] !== $this->transactionSnapshots;
    }

    // --- row-set view (one row per id) -----------------------------------------

    /**
     * @param list<string>              $columns
     * @param array<string, int|string> $scopeColumns
     *
     * @return array<string, mixed>|false
     */
    public function row(string $tableName, string $idColumn, int|string $id, array $columns = [], array $scopeColumns = []): array|false
    {
        $predicate = [...$scopeColumns, $idColumn => $id];
        foreach ($this->tables[$tableName] ?? [] as $row) {
            if (self::rowMatches($row, $predicate)) {
                return self::project($row, $columns);
            }
        }

        return false;
    }

    /**
     * @param list<string>              $columns
     * @param array<string, int|string> $scopeColumns
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(string $tableName, string $idColumn, array $columns = [], array $scopeColumns = []): array
    {
        $keyed = [];
        foreach ($this->tables[$tableName] ?? [] as $row) {
            if (!self::rowMatches($row, $scopeColumns)) {
                continue;
            }
            $key = (string) ($row[$idColumn] ?? '');
            $keyed[$key] = self::project($row, $columns);
        }

        ksort($keyed, SORT_STRING);

        return $keyed;
    }

    /**
     * Upsert a row keyed by `$idColumn` within the optional `$scopeColumns`.
     *
     * @param array<string, mixed>      $row
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
        $this->tables[$tableName][] = $row;
    }

    /**
     * @param array<string, int|string> $scopeColumns
     */
    public function deleteRow(string $tableName, string $idColumn, int|string $id, array $scopeColumns = []): void
    {
        $predicate = [...$scopeColumns, $idColumn => $id];
        $this->removeWhere($tableName, static fn (array $r): bool => self::rowMatches($r, $predicate));
        $this->compactIfEmpty($tableName);
    }

    // --- owned-set view (parent key owns a list of rows) -----------------------

    /**
     * @param array<string, int|string> $parentKey
     *
     * @return list<array<string, mixed>>
     */
    public function rowsForParent(string $tableName, array $parentKey): array
    {
        $rows = [];
        foreach ($this->tables[$tableName] ?? [] as $row) {
            if (self::rowMatches($row, $parentKey)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, int|string>  $parentKey
     * @param list<array<string, mixed>> $rows
     */
    public function replaceOwnedSet(string $tableName, array $parentKey, array $rows): void
    {
        $this->removeWhere($tableName, static fn (array $r): bool => self::rowMatches($r, $parentKey));

        foreach ($rows as $row) {
            $this->tables[$tableName][] = [...$parentKey, ...$row];
        }

        $this->compactIfEmpty($tableName);
    }

    /**
     * Delete every owned set whose parent key contains the supplied
     * partial-key column/value pairs.
     *
     * @param array<string, int|string> $partialParentKey
     */
    public function deleteOwnedSetsMatching(string $tableName, array $partialParentKey): void
    {
        $this->removeWhere($tableName, static fn (array $r): bool => self::rowMatches($r, $partialParentKey));
        $this->compactIfEmpty($tableName);
    }

    // --- shared internals ------------------------------------------------------

    /**
     * @param array<string, mixed>      $row
     * @param array<string, int|string> $predicate
     */
    private static function rowMatches(array $row, array $predicate): bool
    {
        foreach ($predicate as $column => $value) {
            if (!array_key_exists($column, $row) || $row[$column] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     *
     * @return array<string, mixed>
     */
    private static function project(array $row, array $columns): array
    {
        if ([] === $columns) {
            return $row;
        }

        $projected = [];
        foreach ($columns as $column) {
            $projected[$column] = $row[$column] ?? null;
        }

        return $projected;
    }

    private function removeWhere(string $tableName, callable $predicate): void
    {
        if (!isset($this->tables[$tableName])) {
            $this->tables[$tableName] = [];

            return;
        }

        $this->tables[$tableName] = array_values(array_filter(
            $this->tables[$tableName],
            static fn (array $row): bool => !$predicate($row),
        ));
    }

    private function compactIfEmpty(string $tableName): void
    {
        if (isset($this->tables[$tableName]) && [] === $this->tables[$tableName]) {
            unset($this->tables[$tableName]);
        }
    }
}
