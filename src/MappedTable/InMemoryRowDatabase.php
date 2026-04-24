<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final class InMemoryRowDatabase
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $tables = [];

    /** @var list<array<string, array<string, array<string, mixed>>>> */
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

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(string $tableName, string $idColumn, int|string $id, array $columns = []): array|false
    {
        $row = $this->tables[$tableName][$this->rowKey($id)] ?? null;
        if (null === $row) {
            return false;
        }

        if ([] === $columns) {
            return $row;
        }

        $projectedRow = [
            $idColumn => $row[$idColumn],
        ];

        foreach ($columns as $column) {
            $projectedRow[$column] = $row[$column] ?? null;
        }

        return $projectedRow;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function writeRow(string $tableName, string $idColumn, array $row): void
    {
        if (!array_key_exists($idColumn, $row)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot write table "%s" row without id column "%s".',
                $tableName,
                $idColumn,
            ));
        }

        $this->tables[$tableName][$this->rowKey($row[$idColumn])] = $row;
    }

    public function deleteRow(string $tableName, string $idColumn, int|string $id): void
    {
        unset($this->tables[$tableName][$this->rowKey($id)]);
    }

    private function rowKey(int|string $id): string
    {
        return (string) $id;
    }
}
