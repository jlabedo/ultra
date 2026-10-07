<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/**
 * In-memory backing store for `InMemoryMappedRowSetAdapter`.
 *
 * Storage is a single list of rows per table — the same shape Postgres has.
 * Row keys and scopes filter that list by column predicates. Transactions
 * snapshot the table map and restore it on rollback.
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

        $this->removeWhere($tableName, static fn(array $r): bool => ($r[$idColumn] ?? null) === $row[$idColumn]);
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
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function rowByKey(string $tableName, MappedKey $key, array $columns = []): array|false
    {
        foreach ($this->tables[$tableName] ?? [] as $row) {
            if (self::rowMatches($row, $key->values)) {
                return self::project($row, $columns);
            }
        }

        return false;
    }

    /**
     * @param list<string> $keyColumns
     * @param list<string> $columns
     *
     * @return list<MappedRow>
     */
    public function rowsByKey(
        string $tableName,
        array $keyColumns,
        array $columns = [],
        ?MappedKey $scope = null,
    ): array {
        $rows = [];
        foreach ($this->tables[$tableName] ?? [] as $row) {
            if (null !== $scope && !self::rowMatches($row, $scope->values)) {
                continue;
            }

            $keyValues = [];
            foreach ($keyColumns as $column) {
                $value = $row[$column] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    throw new \LogicException(sprintf(
                        'Table "%s" row has no scalar key column "%s".',
                        $tableName,
                        $column,
                    ));
                }
                $keyValues[$column] = $value;
            }

            $projectedColumns = [] === $columns
                ? array_diff_key($row, array_flip($keyColumns))
                : self::project($row, $columns);
            $rows[] = new MappedRow(new MappedKey($keyValues), $projectedColumns);
        }

        usort(
            $rows,
            static fn(MappedRow $left, MappedRow $right): int => (
                $left->key->fingerprint() <=> $right->key->fingerprint()
            ),
        );

        return $rows;
    }

    /** @param array<string, mixed> $columns */
    public function writeRowByKey(string $tableName, MappedKey $key, array $columns): void
    {
        $this->deleteRowByKey($tableName, $key);
        $this->tables[$tableName][] = [...$key->values, ...$columns];
    }

    public function deleteRowByKey(string $tableName, MappedKey $key): void
    {
        $this->removeWhere($tableName, static fn(array $row): bool => self::rowMatches($row, $key->values));
        $this->compactIfEmpty($tableName);
    }

    /**
     * @param list<string>              $columns
     * @param array<string, int|string> $scopeColumns
     *
     * @return array<string, mixed>|false
     */
    public function row(
        string $tableName,
        string $idColumn,
        int|string $id,
        array $columns = [],
        array $scopeColumns = [],
    ): array|false {
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

        $id = $row[$idColumn];
        assert(is_int($id) || is_string($id), 'Validated rows have an integer or string identifier.');
        $this->deleteRow($tableName, $idColumn, $id, $scopeColumns);
        $this->tables[$tableName][] = $row;
    }

    /**
     * @param array<string, int|string> $scopeColumns
     */
    public function deleteRow(string $tableName, string $idColumn, int|string $id, array $scopeColumns = []): void
    {
        $predicate = [...$scopeColumns, $idColumn => $id];
        $this->removeWhere($tableName, static fn(array $r): bool => self::rowMatches($r, $predicate));
        $this->compactIfEmpty($tableName);
    }

    // --- shared internals ------------------------------------------------------

    /**
     * @param array<array-key, mixed>   $row
     * @param array<array-key, int|string> $predicate
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
            static fn(array $row): bool => !$predicate($row),
        ));
    }

    private function compactIfEmpty(string $tableName): void
    {
        if (isset($this->tables[$tableName]) && [] === $this->tables[$tableName]) {
            unset($this->tables[$tableName]);
        }
    }
}
