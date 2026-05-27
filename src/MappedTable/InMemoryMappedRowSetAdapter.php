<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class InMemoryMappedRowSetAdapter implements MappedRowSetAdapter
{
    public function __construct(
        private readonly InMemoryRowDatabase $database,
        private readonly string $tableName,
        private readonly string $idColumn = 'id',
        /** @var array<string, int|string> */
        private readonly array $scopeColumns = [],
    ) {}

    public function row(int|string $id, array $columns = []): array|false
    {
        $row = $this->database->row($this->tableName, $this->idColumn, id: $id, columns: $columns, scopeColumns: $this->scopeColumns);

        return $row;
    }

    public function rows(array $columns = []): array
    {
        return $this->database->rows($this->tableName, $this->idColumn, $columns, $this->scopeColumns);
    }

    public function apply(RowSetUpdatePlan $plan): void
    {
        $this->assertExpectedRowIds($plan->expectedRowIds);

        foreach ($plan->rowUpdates as $rowUpdate) {
            switch ($rowUpdate->mode) {
                case RowUpdatePlan::MODE_DELETE:
                    $this->applyDelete($rowUpdate);

                    break;

                case RowUpdatePlan::MODE_INSERT:
                    $this->applyInsert($rowUpdate);

                    break;

                case RowUpdatePlan::MODE_REPLACE:
                    $this->applyReplace($rowUpdate);

                    break;

                case RowUpdatePlan::MODE_UPSERT:
                    $this->applyUpsert($rowUpdate);

                    break;

                case RowUpdatePlan::MODE_UPDATE:
                    $this->applyUpdate($rowUpdate);

                    break;

                default:
                    throw new \LogicException(sprintf(
                        'Unsupported row update mode "%s" for table "%s".',
                        $rowUpdate->mode,
                        $this->tableName,
                    ));
            }
        }
    }

    /**
     * @param list<int|string>|null $expectedRowIds
     */
    private function assertExpectedRowIds(?array $expectedRowIds): void
    {
        if (null === $expectedRowIds) {
            return;
        }

        $actualRowIds = array_keys($this->database->rows($this->tableName, $this->idColumn, scopeColumns: $this->scopeColumns));
        sort($actualRowIds, SORT_STRING);
        $expectedRowKeys = array_map(static fn (int|string $id): string => (string) $id, $expectedRowIds);
        sort($expectedRowKeys, SORT_STRING);

        if ($actualRowIds !== $expectedRowKeys) {
            throw new ConcurrencyException(sprintf(
                'Row-set expectation failed for table "%s": row ids changed.',
                $this->tableName,
            ));
        }
    }

    private function applyDelete(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, sprintf(
            'Conditional delete failed for table "%s" and row "%s".',
            $this->tableName,
            (string) $update->id,
        ));
        $this->database->deleteRow($this->tableName, $this->idColumn, $update->id, $this->scopeColumns);
    }

    private function applyInsert(RowUpdatePlan $update): void
    {
        if (false !== $this->database->row($this->tableName, $this->idColumn, $update->id, scopeColumns: $this->scopeColumns)) {
            throw new ConcurrencyException(sprintf(
                'Insert failed for table "%s" and row "%s" because the row already exists.',
                $this->tableName,
                (string) $update->id,
            ));
        }

        $this->database->writeRow($this->tableName, $this->idColumn, $this->newRow($update), $this->scopeColumns);
    }

    private function applyReplace(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, sprintf(
            'Conditional delete failed for table "%s" and row "%s".',
            $this->tableName,
            (string) $update->id,
        ));
        $this->database->writeRow($this->tableName, $this->idColumn, $this->newRow($update), $this->scopeColumns);
    }

    private function applyUpsert(RowUpdatePlan $update): void
    {
        $row = $this->database->row($this->tableName, $this->idColumn, $update->id, scopeColumns: $this->scopeColumns);
        if (false === $row) {
            $this->database->writeRow($this->tableName, $this->idColumn, $this->newRow($update), $this->scopeColumns);

            return;
        }

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        $this->database->writeRow($this->tableName, $this->idColumn, $row, $this->scopeColumns);
    }

    private function applyUpdate(RowUpdatePlan $update): void
    {
        $row = $this->requireMatchingRow($update, sprintf(
            'Conditional update failed for table "%s" and row "%s".',
            $this->tableName,
            (string) $update->id,
        ));

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        $this->database->writeRow($this->tableName, $this->idColumn, $row, $this->scopeColumns);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMatchingRow(
        RowUpdatePlan $update,
        string $message,
    ): array {
        $row = $this->database->row($this->tableName, $this->idColumn, $update->id, scopeColumns: $this->scopeColumns);
        if (false === $row) {
            throw new ConcurrencyException($message);
        }

        foreach ($update->expectedColumns as $column => $expectedValue) {
            if (($row[$column] ?? null) !== $expectedValue) {
                throw new ConcurrencyException($message);
            }
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function newRow(RowUpdatePlan $update): array
    {
        $row = [$this->idColumn => $update->id];
        foreach ($this->scopeColumns as $column => $value) {
            $row[$column] = $value;
        }

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }
}
