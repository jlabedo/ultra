<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\UnitOfWork;

/**
 * Per-table unit of work that buffers conditional row changes and flushes them through an adapter.
 */
final class MappedTable implements UnitOfWork
{
    /** @var array<string, BufferedRowUpdate> */
    private array $updates = [];

    public function __construct(
        private readonly MappedTableAdapter $adapter,
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

        $plans = [];
        foreach ($updates as $update) {
            $plan = $update->toPlan();
            if (null !== $plan) {
                $plans[] = $plan;
            }
        }

        $this->adapter->apply($this->tableName, $this->idColumn, $plans);
    }

    public function clear(): void
    {
        $this->updates = [];
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
