<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class InMemoryMappedTableAdapter implements MappedTableAdapter
{
    public function __construct(
        private readonly InMemoryRowDatabase $database,
    ) {}

    public function apply(string $tableName, string $idColumn, array $rowUpdates): void
    {
        foreach ($rowUpdates as $rowUpdate) {
            switch ($rowUpdate->mode) {
                case RowUpdatePlan::MODE_DELETE:
                    $this->applyDelete($tableName, $idColumn, $rowUpdate);
                    break;

                case RowUpdatePlan::MODE_INSERT:
                    $this->applyInsert($tableName, $idColumn, $rowUpdate);
                    break;

                case RowUpdatePlan::MODE_REPLACE:
                    $this->applyReplace($tableName, $idColumn, $rowUpdate);
                    break;

                case RowUpdatePlan::MODE_UPSERT:
                    $this->applyUpsert($tableName, $idColumn, $rowUpdate);
                    break;

                case RowUpdatePlan::MODE_UPDATE:
                    $this->applyUpdate($tableName, $idColumn, $rowUpdate);
                    break;

                default:
                    throw new \LogicException(sprintf(
                        'Unsupported row update mode "%s" for table "%s".',
                        $rowUpdate->mode,
                        $tableName,
                    ));
            }
        }
    }

    private function applyDelete(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($tableName, $idColumn, $update, sprintf(
            'Conditional delete failed for table "%s" and row "%s".',
            $tableName,
            (string) $update->id,
        ));
        $this->database->deleteRow($tableName, $idColumn, $update->id);
    }

    private function applyInsert(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        if (false !== $this->database->row($tableName, $idColumn, $update->id)) {
            throw new ConcurrencyException(sprintf(
                'Insert failed for table "%s" and row "%s" because the row already exists.',
                $tableName,
                (string) $update->id,
            ));
        }

        $this->database->writeRow($tableName, $idColumn, $this->newRow($idColumn, $update));
    }

    private function applyReplace(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $this->requireMatchingRow($tableName, $idColumn, $update, sprintf(
            'Conditional delete failed for table "%s" and row "%s".',
            $tableName,
            (string) $update->id,
        ));
        $this->database->writeRow($tableName, $idColumn, $this->newRow($idColumn, $update));
    }

    private function applyUpsert(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $row = $this->database->row($tableName, $idColumn, $update->id);
        if (false === $row) {
            $this->database->writeRow($tableName, $idColumn, $this->newRow($idColumn, $update));

            return;
        }

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        $this->database->writeRow($tableName, $idColumn, $row);
    }

    private function applyUpdate(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $row = $this->requireMatchingRow($tableName, $idColumn, $update, sprintf(
            'Conditional update failed for table "%s" and row "%s".',
            $tableName,
            (string) $update->id,
        ));

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        $this->database->writeRow($tableName, $idColumn, $row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMatchingRow(
        string $tableName,
        string $idColumn,
        RowUpdatePlan $update,
        string $message,
    ): array
    {
        $row = $this->database->row($tableName, $idColumn, $update->id);
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
    private function newRow(string $idColumn, RowUpdatePlan $update): array
    {
        $row = [$idColumn => $update->id];

        foreach ($update->setColumns as $column => $value) {
            $row[$column] = $value;
        }

        return $row;
    }
}
