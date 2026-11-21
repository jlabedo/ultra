<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** A named query guard bound to one mapped-table unit of work. */
final readonly class QueryGuard
{
    /** @internal Obtain through MappedTableUnitOfWork::queryGuard() or the container. */
    public function __construct(
        private MappedTableUnitOfWork $unitOfWork,
        private string $name,
    ) {}

    public function lock(int|string $value): void
    {
        $this->unitOfWork->lockQuery($this->name, $value);
    }

    /**
     * @template T
     * @param callable(): T $query Repeatable, read-only storage query using the flush connection.
     * @return T
     */
    public function observe(int|string $value, callable $query): mixed
    {
        return $this->unitOfWork->observeQuery($this->name, $value, $query);
    }
}
