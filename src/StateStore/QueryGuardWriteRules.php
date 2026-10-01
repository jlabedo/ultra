<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\MappedTable\RowUpdatePlan;
use Ultra\MappedTable\MappedKey;

/** Derive old/new guard keys from conditional row plans, without additional database reads. */
final class QueryGuardWriteRules
{
    /**
     * @param array<string, RowSetUpdatePlan> $plans
     * @param array<string, list<array{tableKey: string, keyBy: string, columns: list<string>|null}>> $definitions
     * @return iterable<array{name: string, value: int|string, tableKey: string, key: MappedKey}>
     */
    public static function requests(array $plans, array $definitions): iterable
    {
        foreach ($definitions as $name => $scopes) {
            foreach ($scopes as $scope) {
                foreach ($plans[$scope['tableKey']]->rowUpdates as $plan) {
                    if (!$plan->writes()) {
                        continue;
                    }
                    foreach (self::requestsForPlan($name, $scope['keyBy'], $scope['columns'], $plan) as $request) {
                        yield [...$request, 'tableKey' => $scope['tableKey'], 'key' => $plan->key];
                    }
                }
            }
        }
    }

    /** @param list<string>|null $columns @return iterable<array{name: string, value: int|string}> */
    private static function requestsForPlan(string $name, string $keyBy, ?array $columns, RowUpdatePlan $plan): iterable
    {
        $insert = RowUpdatePlan::MODE_INSERT === $plan->mode;
        $delete = RowUpdatePlan::MODE_DELETE === $plan->mode;
        $before = [...$plan->expectedColumns, ...$plan->key->values];
        $after = RowUpdatePlan::MODE_REPLACE === $plan->mode
            ? [...$plan->setColumns, ...$plan->key->values]
            : [...$before, ...$plan->setColumns];
        $affected = $insert || $delete || null === $columns || in_array($plan->mode, [RowUpdatePlan::MODE_REPLACE, RowUpdatePlan::MODE_UPSERT], true);
        foreach ([$keyBy, ...($columns ?? [])] as $column) {
            if (array_key_exists($column, $plan->setColumns)
                && (!array_key_exists($column, $before) || $plan->setColumns[$column] !== $before[$column])) {
                $affected = true;
            }
        }
        if (!$affected) {
            return;
        }

        if (!$insert && (!array_key_exists($keyBy, $before) || RowUpdatePlan::MODE_UPSERT === $plan->mode)) {
            throw new \LogicException(sprintf(
                'Query guard "%s" requires observing column "%s" before changing an existing row (or observing its absence before inserting).',
                $name, $keyBy,
            ));
        }
        foreach ([$insert ? null : $before, $delete ? null : $after] as $row) {
            if (null === $row) {
                continue;
            }
            $value = $row[$keyBy] ?? null;
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException(sprintf('Query guard "%s" needs an int or string value for column "%s".', $name, $keyBy));
            }
            yield ['name' => $name, 'value' => $value];
        }
    }
}
