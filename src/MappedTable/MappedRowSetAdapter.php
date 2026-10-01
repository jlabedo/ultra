<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

interface MappedRowSetAdapter
{
    public function tableName(): string;

    /** Acquire a cooperative, transaction-scoped manual lock, including when the row is absent. */
    public function lock(MappedKey $key): void;

    /** Acquire a transaction-scoped named query guard, independent of the row's primary key. */
    public function lockGuard(string $name, int|string $value, GuardLockMode $mode): void;

    /** @return list<string> */
    public function keyColumns(): array;

    public function scope(): ?MappedKey;

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|false
     */
    public function row(MappedKey $key, array $columns = []): array|false;

    /**
     * @param list<string> $columns
     *
     * @return list<MappedRow>
     */
    public function rows(array $columns = []): array;

    public function apply(RowSetUpdatePlan $plan): void;
}
