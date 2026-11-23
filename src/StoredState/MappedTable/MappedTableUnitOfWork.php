<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\MappedTable\Guard\QueryGuardRules;
use Ultra\MappedTable\Guard\QueryGuardWork;
use Ultra\MappedTable\Plan\RowSetDependencies;
use Ultra\MappedTable\Plan\RowSetUpdatePlan;
use Ultra\MappedTable\Plan\RowUpdatePlan;
use Ultra\UnitOfWork\UnitOfWork;

/** Coordinates the defined row sets that persist in one transaction. */
final readonly class MappedTableUnitOfWork implements UnitOfWork
{
    /** @var array<string, MappedRowSet> */
    private array $rowSets;

    /** @var array<string, MappedRowSetMetadata> */
    private array $mappings;

    /** @var array<string, bool> */
    private array $referenced;

    private QueryGuardWork $guards;

    /**
     * @param array<string, MappedRowSet> $rowSets Keyed by definition class when used with declared dependencies or guards.
     * @param array<string, bool>|null $compiledSchedule Supplied by the container compiler.
     * @param list<QueryGuardDefinition>|QueryGuardRules $queryGuards
     */
    public function __construct(
        array $rowSets,
        private ?MappedTableAdapter $adapter = null,
        ?array $compiledSchedule = null,
        array|QueryGuardRules $queryGuards = [],
        ?GuardLockAdapter $guardLocks = null,
    ) {
        $mappings = [];
        foreach ($rowSets as $definition => $rowSet) {
            $rowSet->assertCanEnroll($adapter ?? throw new \LogicException('Mapped row sets require a table adapter.'));
            $mappings[$definition] = $rowSet->mappingForCoordination();
        }

        $schedule = $compiledSchedule ?? RowSetDependencies::compile($mappings);
        $rules = $queryGuards instanceof QueryGuardRules
            ? $queryGuards
            : QueryGuardRules::compile($queryGuards, $mappings);
        if ([] !== $rules->namespaces && null === $guardLocks) {
            throw new \LogicException('Mapped query guards require a GuardLockAdapter.');
        }
        $orderedRowSets = [];
        foreach (array_keys($schedule) as $definition) {
            $rowSet = $rowSets[$definition];
            $rowSet->enrollInCoordinator();
            $orderedRowSets[$definition] = $rowSet;
        }

        $this->guards = new QueryGuardWork($rules, $guardLocks, $orderedRowSets);
        $this->rowSets = $orderedRowSets;
        $this->mappings = $mappings;
        $this->referenced = $schedule;
    }

    public function queryGuard(string $name): QueryGuard
    {
        return $this->guards->guard($name);
    }

    public function lockRow(string $table, MappedKey $key): void
    {
        $this->guards->lockRow($table, $key);
    }

    /** @param callable(): mixed $query */
    public function expectQuery(callable $query, mixed $expected, ?string $name = null): void
    {
        $this->guards->expectQuery($query, $expected, $name);
    }

    public function hasPendingChanges(): bool
    {
        return (
            $this->guards->hasPendingChanges()
            || array_any($this->rowSets, static fn(MappedRowSet $rowSet): bool => $rowSet->hasPendingChanges())
        );
    }

    public function flush(): void
    {
        $plans = [];
        foreach ($this->rowSets as $definition => $rowSet) {
            if (!$rowSet->hasPendingChanges()) {
                continue;
            }

            $plans[$definition] = $rowSet->updatePlan();
        }

        $this->assertSupported($plans);
        $requests = $this->guards->requests($plans);
        $resource = [] !== $plans || $this->guards->hasPendingChanges()
            ? $this->adapter?->activeTransactionResource()
            : null;
        $this->guards->replay($requests, $resource);

        if ([] !== $plans) {
            $tablePlans = [];
            foreach ($plans as $definition => $plan) {
                $tablePlans[$this->mappings[$definition]->table] = $plan;
            }
            assert(null !== $this->adapter, 'Pending mapped rows require a table adapter.');
            $this->adapter->flush($tablePlans);
        }

        $this->guards->clear();
        foreach (array_keys($plans) as $definition) {
            $this->rowSets[$definition]->clear();
        }
    }

    public function clear(): void
    {
        $this->guards->clear();
        foreach ($this->rowSets as $rowSet) {
            $rowSet->clear();
        }
    }

    /** @param array<string, RowSetUpdatePlan> $plans */
    private function assertSupported(array $plans): void
    {
        $absent = [];
        $retainedByMembership = [];
        foreach ($plans as $definition => $plan) {
            if (!$this->referenced[$definition]) {
                continue;
            }

            foreach ($plan->rowUpdates as $row) {
                if (RowUpdatePlan::MODE_REPLACE === $row->mode) {
                    throw new \LogicException(sprintf(
                        'Cannot replace a row of referenced table "%s".',
                        $this->mappings[$definition]->table,
                    ));
                }

                if (
                    RowUpdatePlan::MODE_DELETE === $row->mode
                    || RowUpdatePlan::MODE_EXPECT === $row->mode
                    && RowUpdatePlan::EXPECTED_ABSENT === $row->expectedPresence
                ) {
                    $absent[$definition][$row->key->fingerprint()] = true;
                }
            }
            foreach ($plan->memberships as $membership) {
                if (null === $membership->retainedRowKeys) {
                    continue;
                }

                $keys = array_map(
                    static fn(MappedKey $key): string => $key->fingerprint(),
                    $membership->retainedRowKeys,
                );
                $retainedByMembership[$definition][$membership->owner?->fingerprint() ?? ''] = array_fill_keys(
                    $keys,
                    true,
                );
            }
        }

        foreach ($plans as $definition => $plan) {
            $mapping = $this->mappings[$definition];
            $owner = $mapping->owner;
            if (
                null === $owner
                || !isset($absent[$owner->definition]) && !isset($retainedByMembership[$owner->definition])
            ) {
                continue;
            }

            $parent = $this->mappings[$owner->definition];
            $check = static function (MappedKey $childKey) use ($owner, $parent, $absent, $retainedByMembership): void {
                $parentKey = $owner->parentKey($childKey);
                $fingerprint = $parentKey->fingerprint();
                $removed = isset($absent[$owner->definition][$fingerprint]);
                $parentMemberships = $retainedByMembership[$owner->definition] ?? [];
                if ([] !== $parentMemberships) {
                    $id = [] === $parent->ownerColumns ? '' : $parent->ownerKey($parentKey)->fingerprint();
                    $retained = $parentMemberships[$id] ?? null;
                    $removed = $removed || null !== $retained && !isset($retained[$fingerprint]);
                }

                if ($removed) {
                    throw new \LogicException(sprintf(
                        'Cannot retain mapped row %s because the flush leaves its parent %s absent from "%s".',
                        $childKey,
                        $parentKey,
                        $parent->table,
                    ));
                }
            };

            foreach ($plan->rowUpdates as $row) {
                if (!$row->writes() || RowUpdatePlan::MODE_DELETE === $row->mode) {
                    continue;
                }

                $check($row->key);
            }
            foreach ($plan->memberships as $membership) {
                foreach ($membership->retainedRowKeys ?? [] as $key) {
                    $check($key);
                }
            }
        }
    }
}
