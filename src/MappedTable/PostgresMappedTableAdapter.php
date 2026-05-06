<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

final class PostgresMappedTableAdapter implements MappedTableAdapter
{
    /**
     * @param array<string, PostgresMappedColumnType> $columnTypes
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
        private readonly string $idColumn = 'id',
        private readonly array $columnTypes = [],
    ) {}

    public function row(int|string $id, array $columns = []): array|false
    {
        $selectColumns = [] === $columns ? [$this->idColumn] : $columns;
        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            array_values(array_unique($selectColumns)),
        );

        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM %s WHERE %s = ?',
                implode(', ', $quotedColumns),
                $this->connection->quoteSingleIdentifier($this->tableName),
                $this->connection->quoteSingleIdentifier($this->idColumn),
            ),
            [$id],
        );

        return false === $row ? false : $this->decodeRow($row);
    }

    public function apply(array $rowUpdates): void
    {
        foreach ($this->groupConsecutive($rowUpdates) as $batch) {
            $mode = $batch[0]->mode;
            $count = count($batch);

            switch ($mode) {
                case RowUpdatePlan::MODE_DELETE:
                    $count > 1
                        ? $this->flushBatchDelete($batch)
                        : $this->flushDelete($batch[0]);

                    break;

                case RowUpdatePlan::MODE_INSERT:
                    $count > 1
                        ? $this->flushBatchInsert($batch)
                        : $this->flushInsert($batch[0]);

                    break;

                case RowUpdatePlan::MODE_REPLACE:
                    $count > 1
                        ? $this->flushBatchReplace($batch)
                        : $this->flushReplace($batch[0]);

                    break;

                case RowUpdatePlan::MODE_UPSERT:
                    $count > 1
                        ? $this->flushBatchUpsert($batch)
                        : $this->flushUpsert($batch[0]);

                    break;

                case RowUpdatePlan::MODE_UPDATE:
                    $this->flushUpdate($batch[0]);

                    break;

                default:
                    throw new \LogicException(sprintf(
                        'Unsupported row update mode "%s" for table "%s".',
                        $mode,
                        $this->tableName,
                    ));
            }
        }
    }

    /** @return list<list<RowUpdatePlan>> */
    private function groupConsecutive(array $rowUpdates): array
    {
        $groups = [];
        $currentKey = null;
        $currentGroup = [];

        foreach ($rowUpdates as $update) {
            $key = $this->batchKey($update);
            if ($key !== $currentKey) {
                if ([] !== $currentGroup) {
                    $groups[] = $currentGroup;
                }
                $currentKey = $key;
                $currentGroup = [];
            }
            $currentGroup[] = $update;
        }

        if ([] !== $currentGroup) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    private function batchKey(RowUpdatePlan $update): string
    {
        return match ($update->mode) {
            RowUpdatePlan::MODE_INSERT => 'insert:'.implode(',', array_keys($update->setColumns)),
            RowUpdatePlan::MODE_UPSERT => 'upsert:'.implode(',', array_keys($update->setColumns)),
            RowUpdatePlan::MODE_DELETE => [] === $update->expectedColumns
                ? 'delete'
                : 'delete:'.(string) $update->id,
            RowUpdatePlan::MODE_REPLACE => [] === $update->expectedColumns
                ? 'replace:'.implode(',', array_keys($update->setColumns))
                : 'replace:'.(string) $update->id,
            default => $update->mode.':'.(string) $update->id,
        };
    }

    /**
     * @param list<RowUpdatePlan> $updates
     */
    private function flushBatchDelete(array $updates): void
    {
        $ids = array_map(static fn ($u) => $u->id, $updates);

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE %s IN (%s)',
                $this->connection->quoteSingleIdentifier($this->tableName),
                $this->connection->quoteSingleIdentifier($this->idColumn),
                implode(', ', array_fill(0, count($ids), '?')),
            ),
            $ids,
        );

        if ($affectedRows !== count($updates)) {
            throw new ConcurrencyException(sprintf(
                'Batch delete failed for table "%s": expected %d deletions, got %d.',
                $this->tableName,
                count($updates),
                $affectedRows,
            ));
        }
    }

    /**
     * @param list<RowUpdatePlan> $updates
     */
    private function flushBatchInsert(array $updates): void
    {
        $columns = [$this->idColumn, ...array_keys($updates[0]->setColumns)];
        $quotedColumns = array_map($this->connection->quoteSingleIdentifier(...), $columns);
        $rowPlaceholder = '('.implode(', ', array_map($this->placeholder(...), $columns)).')';

        $params = [];
        foreach ($updates as $update) {
            $params[] = $update->id;
            foreach ($update->setColumns as $column => $value) {
                $params[] = $this->encodeColumnValue($column, $value);
            }
        }

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO NOTHING',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_fill(0, count($updates), $rowPlaceholder)),
                $this->connection->quoteSingleIdentifier($this->idColumn),
            ),
            $params,
        );

        if ($affectedRows !== count($updates)) {
            throw new ConcurrencyException(sprintf(
                'Batch insert failed for table "%s": %d of %d rows already existed.',
                $this->tableName,
                count($updates) - $affectedRows,
                count($updates),
            ));
        }
    }

    /**
     * @param list<RowUpdatePlan> $updates
     */
    private function flushBatchReplace(array $updates): void
    {
        $this->flushBatchDelete($updates);
        $this->flushBatchInsert($updates);
    }

    /**
     * @param list<RowUpdatePlan> $updates
     */
    private function flushBatchUpsert(array $updates): void
    {
        $columns = [$this->idColumn, ...array_keys($updates[0]->setColumns)];
        $quotedColumns = array_map($this->connection->quoteSingleIdentifier(...), $columns);
        $rowPlaceholder = '('.implode(', ', array_map($this->placeholder(...), $columns)).')';
        $allPlaceholders = implode(', ', array_fill(0, count($updates), $rowPlaceholder));

        $params = [];
        foreach ($updates as $update) {
            $params[] = $update->id;
            foreach ($update->setColumns as $column => $value) {
                $params[] = $this->encodeColumnValue($column, $value);
            }
        }

        if ([] === $updates[0]->setColumns) {
            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO NOTHING',
                    $this->connection->quoteSingleIdentifier($this->tableName),
                    implode(', ', $quotedColumns),
                    $allPlaceholders,
                    $this->connection->quoteSingleIdentifier($this->idColumn),
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
                'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $quotedColumns),
                $allPlaceholders,
                $this->connection->quoteSingleIdentifier($this->idColumn),
                implode(', ', $assignments),
            ),
            $params,
        );
    }

    private function flushDelete(RowUpdatePlan $update): void
    {
        $whereClauses = [
            sprintf('%s = ?', $this->connection->quoteSingleIdentifier($this->idColumn)),
        ];
        $params = [$update->id];

        foreach ($update->expectedColumns as $column => $value) {
            $whereClauses[] = sprintf(
                '%s IS NOT DISTINCT FROM %s',
                $this->connection->quoteSingleIdentifier($column),
                $this->placeholder($column),
            );
            $params[] = $this->encodeColumnValue($column, $value);
        }

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'DELETE FROM %s WHERE %s',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(' AND ', $whereClauses),
            ),
            $params,
        );

        if (1 !== $affectedRows) {
            throw new ConcurrencyException(sprintf(
                'Conditional delete failed for table "%s" and row "%s".',
                $this->tableName,
                (string) $update->id,
            ));
        }
    }

    private function flushInsert(RowUpdatePlan $update): void
    {
        $columns = [$this->idColumn];
        $params = [$update->id];

        foreach ($update->setColumns as $column => $value) {
            $columns[] = $column;
            $params[] = $this->encodeColumnValue($column, $value);
        }

        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            $columns,
        );

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_map($this->placeholder(...), $columns)),
                $this->connection->quoteSingleIdentifier($this->idColumn),
            ),
            $params,
        );

        if (1 !== $affectedRows) {
            throw new ConcurrencyException(sprintf(
                'Insert failed for table "%s" and row "%s" because the row already exists.',
                $this->tableName,
                (string) $update->id,
            ));
        }
    }

    private function flushReplace(RowUpdatePlan $update): void
    {
        $this->flushDelete($update);
        $this->flushInsert($update);
    }

    private function flushUpsert(RowUpdatePlan $update): void
    {
        $columns = [$this->idColumn];
        $params = [$update->id];

        foreach ($update->setColumns as $column => $value) {
            $columns[] = $column;
            $params[] = $this->encodeColumnValue($column, $value);
        }

        $quotedColumns = array_map(
            $this->connection->quoteSingleIdentifier(...),
            $columns,
        );

        if ([] === $update->setColumns) {
            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                    $this->connection->quoteSingleIdentifier($this->tableName),
                    implode(', ', $quotedColumns),
                    implode(', ', array_map($this->placeholder(...), $columns)),
                    $this->connection->quoteSingleIdentifier($this->idColumn),
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
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_map($this->placeholder(...), $columns)),
                $this->connection->quoteSingleIdentifier($this->idColumn),
                implode(', ', $assignments),
            ),
            $params,
        );
    }

    private function flushUpdate(RowUpdatePlan $update): void
    {
        $setClauses = [];
        $params = [];

        foreach ($update->setColumns as $column => $value) {
            $setClauses[] = sprintf(
                '%s = %s',
                $this->connection->quoteSingleIdentifier($column),
                $this->placeholder($column),
            );
            $params[] = $this->encodeColumnValue($column, $value);
        }

        $whereClauses = [
            sprintf('%s = ?', $this->connection->quoteSingleIdentifier($this->idColumn)),
        ];
        $params[] = $update->id;

        foreach ($update->expectedColumns as $column => $value) {
            $whereClauses[] = sprintf(
                '%s IS NOT DISTINCT FROM %s',
                $this->connection->quoteSingleIdentifier($column),
                $this->placeholder($column),
            );
            $params[] = $this->encodeColumnValue($column, $value);
        }

        $affectedRows = $this->connection->executeStatement(
            sprintf(
                'UPDATE %s SET %s WHERE %s',
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $setClauses),
                implode(' AND ', $whereClauses),
            ),
            $params,
        );

        if (1 !== $affectedRows) {
            throw new ConcurrencyException(sprintf(
                'Conditional update failed for table "%s" and row "%s".',
                $this->tableName,
                (string) $update->id,
            ));
        }
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
