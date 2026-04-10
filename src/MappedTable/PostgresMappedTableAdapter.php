<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class PostgresMappedTableAdapter implements MappedTableAdapter
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function row(string $tableName, string $idColumn, int|string $id, array $columns = []): array|false
    {
        $selectColumns = [$idColumn, ...$columns];
        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            array_values(array_unique($selectColumns)),
        );

        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM %s WHERE %s = ?',
                implode(', ', $quotedColumns),
                $this->connection->quoteSingleIdentifier($tableName),
                $this->connection->quoteSingleIdentifier($idColumn),
            ),
            [$id],
        );

        return false === $row ? false : $row;
    }

    public function apply(string $tableName, string $idColumn, array $rowUpdates): void
    {
        foreach ($rowUpdates as $rowUpdate) {
            switch ($rowUpdate->mode) {
                case RowUpdatePlan::MODE_DELETE:
                    $this->flushDelete($tableName, $idColumn, $rowUpdate);

                    break;

                case RowUpdatePlan::MODE_INSERT:
                    $this->flushInsert($tableName, $idColumn, $rowUpdate);

                    break;

                case RowUpdatePlan::MODE_REPLACE:
                    $this->flushReplace($tableName, $idColumn, $rowUpdate);

                    break;

                case RowUpdatePlan::MODE_UPSERT:
                    $this->flushUpsert($tableName, $idColumn, $rowUpdate);

                    break;

                case RowUpdatePlan::MODE_UPDATE:
                    $this->flushUpdate($tableName, $idColumn, $rowUpdate);

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

    private function flushDelete(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $whereClauses = [
            sprintf('%s = ?', $this->connection->quoteSingleIdentifier($idColumn)),
        ];
        $params = [$update->id];

        foreach ($update->expectedColumns as $column => $value) {
            $whereClauses[] = sprintf('%s IS NOT DISTINCT FROM ?', $this->connection->quoteSingleIdentifier($column));
            $params[] = $value;
        }

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE %s',
                $this->connection->quoteSingleIdentifier($tableName),
                implode(' AND ', $whereClauses),
            ),
            $params,
        );

        if (1 !== $affectedRows) {
            throw new ConcurrencyException(sprintf(
                'Conditional delete failed for table "%s" and row "%s".',
                $tableName,
                (string) $update->id,
            ));
        }
    }

    private function flushInsert(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $columns = [$idColumn];
        $params = [$update->id];

        foreach ($update->setColumns as $column => $value) {
            $columns[] = $column;
            $params[] = $value;
        }

        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            $columns,
        );

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                $this->connection->quoteSingleIdentifier($tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_fill(0, count($columns), '?')),
                $this->connection->quoteSingleIdentifier($idColumn),
            ),
            $params,
        );

        if (1 !== $affectedRows) {
            throw new ConcurrencyException(sprintf(
                'Insert failed for table "%s" and row "%s" because the row already exists.',
                $tableName,
                (string) $update->id,
            ));
        }
    }

    private function flushReplace(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $this->flushDelete($tableName, $idColumn, $update);
        $this->flushInsert($tableName, $idColumn, $update);
    }

    private function flushUpsert(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $columns = [$idColumn];
        $params = [$update->id];

        foreach ($update->setColumns as $column => $value) {
            $columns[] = $column;
            $params[] = $value;
        }

        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            $columns,
        );

        if ([] === $update->setColumns) {
            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                    $this->connection->quoteSingleIdentifier($tableName),
                    implode(', ', $quotedColumns),
                    implode(', ', array_fill(0, count($columns), '?')),
                    $this->connection->quoteSingleIdentifier($idColumn),
                ),
                $params,
            );

            return;
        }

        $assignments = [];
        foreach (array_slice($columns, 1) as $column) {
            $quotedColumn = $this->connection->quoteSingleIdentifier($column);
            $assignments[] = sprintf('%1$s = EXCLUDED.%1$s', $quotedColumn);
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                $this->connection->quoteSingleIdentifier($tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_fill(0, count($columns), '?')),
                $this->connection->quoteSingleIdentifier($idColumn),
                implode(', ', $assignments),
            ),
            $params,
        );
    }

    private function flushUpdate(string $tableName, string $idColumn, RowUpdatePlan $update): void
    {
        $setClauses = [];
        $params = [];

        foreach ($update->setColumns as $column => $value) {
            $setClauses[] = sprintf('%s = ?', $this->connection->quoteSingleIdentifier($column));
            $params[] = $value;
        }

        $whereClauses = [
            sprintf('%s = ?', $this->connection->quoteSingleIdentifier($idColumn)),
        ];
        $params[] = $update->id;

        foreach ($update->expectedColumns as $column => $value) {
            $whereClauses[] = sprintf('%s IS NOT DISTINCT FROM ?', $this->connection->quoteSingleIdentifier($column));
            $params[] = $value;
        }

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'UPDATE %s SET %s WHERE %s',
                $this->connection->quoteSingleIdentifier($tableName),
                implode(', ', $setClauses),
                implode(' AND ', $whereClauses),
            ),
            $params,
        );

        if (1 !== $affectedRows) {
            throw new ConcurrencyException(sprintf(
                'Conditional update failed for table "%s" and row "%s".',
                $tableName,
                (string) $update->id,
            ));
        }
    }
}
