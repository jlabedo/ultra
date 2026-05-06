<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class InMemoryMappedTableAdapter implements MappedTableAdapter
{
    public function __construct(
        private readonly InMemoryRowDatabase $database,
        private readonly string $tableName,
        private readonly string $idColumn = 'id',
    ) {}

    public function row(int|string $id, array $columns = []): array|false
    {
        return $this->database->row($this->tableName, $this->idColumn, $id, $columns);
    }

    public function apply(array $rowUpdates): void
    {
        foreach ($rowUpdates as $rowUpdate) {
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

    private function applyDelete(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, sprintf(
            'Conditional delete failed for table "%s" and row "%s".',
            $this->tableName,
            (string) $update->id,
        ));
        $this->database->deleteRow($this->tableName, $this->idColumn, $update->id);
    }

    private function applyInsert(RowUpdatePlan $update): void
    {
        if (false !== $this->database->row($this->tableName, $this->idColumn, $update->id)) {
            throw new ConcurrencyException(sprintf(
                'Insert failed for table "%s" and row "%s" because the row already exists.',
                $this->tableName,
                (string) $update->id,
            ));
        }

        $this->database->writeRow($this->tableName, $this->idColumn, $this->newRow($update));
    }

    private function applyReplace(RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($update, sprintf(
            'Conditional delete failed for table "%s" and row "%s".',
            $this->tableName,
            (string) $update->id,
        ));
        $this->database->writeRow($this->tableName, $this->idColumn, $this->newRow($update));
    }

    private function applyUpsert(RowUpdatePlan $update): void
    {
        $row = $this->database->row($this->tableName, $this->idColumn, $update->id);
        if (false === $row) {
            $this->database->writeRow($this->tableName, $this->idColumn, $this->newRow($update));

            return;
        }

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        $this->database->writeRow($this->tableName, $this->idColumn, $row);
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

        $this->database->writeRow($this->tableName, $this->idColumn, $row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMatchingRow(
        RowUpdatePlan $update,
        string $message,
    ): array {
        $row = $this->database->row($this->tableName, $this->idColumn, $update->id);
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

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }
}
