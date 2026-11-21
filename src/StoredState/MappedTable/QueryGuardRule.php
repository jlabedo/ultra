<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

/** @internal A dependency on one compiled row set. */
final readonly class QueryGuardRule
{
    /** @param array<string, true>|null $watchedColumns */
    public function __construct(
        public string $name,
        public string $valueColumn,
        public bool $valueInKey,
        public bool $valueInOwner,
        public ?array $watchedColumns,
    ) {}

    /** @return iterable<array{value: string, key: MappedKey}> */
    public function affectedValues(RowSetUpdatePlan $plan): iterable
    {
        foreach ($plan->rowUpdates as $row) {
            if (!$row->writes() || !$this->affectsQuery($row)) {
                continue;
            }

            foreach ($this->rowValues($row) as $value) {
                yield ['value' => $value, 'key' => $row->key];
            }
        }
        foreach ($plan->ownerCollections as $collection) {
            if (null === $collection->retainedRowKeys) {
                continue;
            }
            if (!$this->valueInOwner) {
                throw new \LogicException(sprintf(
                    'Query guard "%s" requires reconciliation to fix its value column "%s" in the owner key.',
                    $this->name,
                    $this->valueColumn,
                ));
            }

            yield ['value' => (string) $collection->owner->value($this->valueColumn), 'key' => $collection->owner];
        }
    }

    private function affectsQuery(RowUpdatePlan $row): bool
    {
        $watched = $this->watchedColumns;
        if (null === $watched || RowUpdatePlan::MODE_UPDATE !== $row->mode) {
            return true;
        }

        foreach ($row->setColumns as $column => $value) {
            if (
                isset($watched[$column])
                && (!array_key_exists($column, $row->expectedColumns) || $row->expectedColumns[$column] !== $value)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string> */
    private function rowValues(RowUpdatePlan $row): iterable
    {
        if ($this->valueInKey) {
            yield (string) $row->key->value($this->valueColumn);

            return;
        }

        $column = $this->valueColumn;
        $before = null;
        if (RowUpdatePlan::MODE_INSERT !== $row->mode) {
            $before = $row->expectedColumns[$column] ?? null;
            if (RowUpdatePlan::MODE_UPSERT === $row->mode || null === $before) {
                throw new \LogicException(sprintf(
                    'Query guard "%s" requires an expectation on "%s" before changing an existing row, or an explicit insert.',
                    $this->name,
                    $column,
                ));
            }

            yield $this->lockValue($before);
        }

        if (RowUpdatePlan::MODE_DELETE !== $row->mode) {
            $after = match ($row->mode) {
                RowUpdatePlan::MODE_INSERT, RowUpdatePlan::MODE_REPLACE => $row->setColumns[$column] ?? null,
                default => $row->setColumns[$column] ?? $before,
            };
            if (null === $before || $before !== $after) {
                yield $this->lockValue($after);
            }
        }
    }

    private function lockValue(mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \LogicException(sprintf(
                'Query guard "%s" needs an int or string for its value column "%s".',
                $this->name,
                $this->valueColumn,
            ));
        }

        return (string) $value;
    }
}
