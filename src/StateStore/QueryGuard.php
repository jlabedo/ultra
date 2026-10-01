<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\GuardLockMode;

/** Prototype: a compiled, named guard whose requests live in the current unit of work. */
final readonly class QueryGuard
{
    public function __construct(
        private StateTableRegistry $tables,
        private string $name,
        private string $tableKey,
    ) {}

    /** Register a shared request. No database lock is acquired during the decision. */
    public function lock(int|string $value): void
    {
        $this->tables->guard($this->tableKey, $this->name, $value, GuardLockMode::SHARED);
    }

    /**
     * @template T
     * @param callable(): T $query Read committed data, without the pending-write overlay.
     * @return T
     */
    public function observe(int|string $value, callable $query): mixed
    {
        $this->lock($value);
        $result = $query();
        $this->tables->expectQuery($query, $result, $this->name.'('.$value.')');

        return $result;
    }
}
