<?php

declare(strict_types=1);

namespace Ultra\MappedTable_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;

/**
 * Per-table unit of work that buffers conditional row updates and flushes them inside an external transaction.
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
            if ([] === $update->setColumns) {
                continue;
            }

            $this->flushUpdate($update);
        }
    }

    public function clear(): void
    {
        $this->updates = [];
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
