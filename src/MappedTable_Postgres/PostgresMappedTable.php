<?php

declare(strict_types=1);

namespace Ultra\MappedTable_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;

/**
 * Per-table unit of work that buffers conditional row changes and flushes them inside an external transaction.
 */
final class PostgresMappedTable implements UnitOfWork
{
    /** @var array<string, BufferedRowUpdate> */
    private array $updates = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
        private readonly string $idColumn = 'id',
    ) {}

    public function withId(string|int $id): PendingRowUpdate
    {
        $updateKey = self::updateKey($id);
        $update = $this->updates[$updateKey] ??= new BufferedRowUpdate($id);

        return new PendingRowUpdate($update, $this->tableName);
    }

    public function flush(): void
    {
        $updates = array_values($this->updates);
        usort($updates, static fn (BufferedRowUpdate $left, BufferedRowUpdate $right): int => self::compareIds($left->id, $right->id));

        foreach ($updates as $update) {
            switch ($update->mode) {
                case null:
                    break;

                case BufferedRowUpdate::MODE_DELETE:
                    $this->flushDelete($update);
                    break;

                case BufferedRowUpdate::MODE_INSERT:
                    $this->flushInsert($update);
                    break;

                case BufferedRowUpdate::MODE_REPLACE:
                    $this->flushReplace($update);
                    break;

                case BufferedRowUpdate::MODE_UPSERT:
                    $this->flushUpsert($update);
                    break;

                case BufferedRowUpdate::MODE_UPDATE:
                    if ([] !== $update->setColumns) {
                        $this->flushUpdate($update);
                    }
                    break;

                default:
                    throw new \LogicException(sprintf(
                        'Unsupported row update mode "%s" for table "%s".',
                        (string) $update->mode,
                        $this->tableName,
                    ));
            }
        }
    }

    public function clear(): void
    {
        $this->updates = [];
    }

    private function flushDelete(BufferedRowUpdate $update): void
    {
        $whereClauses = [
            sprintf('%s = ?', $this->connection->quoteSingleIdentifier($this->idColumn)),
        ];
        $params = [$update->id];

        foreach ($update->expectedColumns as $column => $value) {
            $whereClauses[] = sprintf('%s IS NOT DISTINCT FROM ?', $this->connection->quoteSingleIdentifier($column));
            $params[] = $value;
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

    private function flushInsert(BufferedRowUpdate $update): void
    {
        $columns = [$this->idColumn];
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
                $this->connection->quoteSingleIdentifier($this->tableName),
                implode(', ', $quotedColumns),
                implode(', ', array_fill(0, count($columns), '?')),
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

    private function flushReplace(BufferedRowUpdate $update): void
    {
        $this->flushDelete($update);
        $this->flushInsert($update);
    }

    private function flushUpsert(BufferedRowUpdate $update): void
    {
        $columns = [$this->idColumn];
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
                    $this->connection->quoteSingleIdentifier($this->tableName),
                    implode(', ', $quotedColumns),
                    implode(', ', array_fill(0, count($columns), '?')),
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
                implode(', ', array_fill(0, count($columns), '?')),
                $this->connection->quoteSingleIdentifier($this->idColumn),
                implode(', ', $assignments),
            ),
            $params,
        );
    }

    private function flushUpdate(BufferedRowUpdate $update): void
    {
        $setClauses = [];
        $params = [];

        foreach ($update->setColumns as $column => $value) {
            $setClauses[] = sprintf('%s = ?', $this->connection->quoteSingleIdentifier($column));
            $params[] = $value;
        }

        $whereClauses = [
            sprintf('%s = ?', $this->connection->quoteSingleIdentifier($this->idColumn)),
        ];
        $params[] = $update->id;

        foreach ($update->expectedColumns as $column => $value) {
            $whereClauses[] = sprintf('%s IS NOT DISTINCT FROM ?', $this->connection->quoteSingleIdentifier($column));
            $params[] = $value;
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

    private static function compareIds(string|int $left, string|int $right): int
    {
        if (is_int($left) && is_int($right)) {
            return $left <=> $right;
        }

        return (string) $left <=> (string) $right;
    }

    private static function updateKey(string|int $id): string
    {
        return (string) $id;
    }
}
