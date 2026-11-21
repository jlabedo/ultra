<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

use Ultra\UnitOfWork\UnitOfWork;

/** Coordinates the defined row sets that persist in one transaction. */
final readonly class MappedTableUnitOfWork implements UnitOfWork
{
    /** @var array<class-string<MappedRowSetDefinition>, MappedRowSet> */
    private array $rowSets;

    /** @var array<class-string<MappedRowSetDefinition>, CoordinatedMappedRowSetAdapter> */
    private array $adapters;

    /** @var array<class-string<MappedRowSetDefinition>, MappedRowSetMetadata> */
    private array $mappings;

    /** @var array<class-string<MappedRowSetDefinition>, bool> */
    private array $referenced;

    /**
     * @param array<class-string<MappedRowSetDefinition>, MappedRowSet> $rowSets
     * @param array<class-string<MappedRowSetDefinition>, bool>|null $compiledSchedule Supplied by the container compiler.
     */
    public function __construct(array $rowSets, ?array $compiledSchedule = null)
    {
        $mappings = [];
        $adapters = [];
        foreach ($rowSets as $definition => $rowSet) {
            $mappings[$definition] = $rowSet->mappingForCoordination();
            $adapters[$definition] = $rowSet->adapterForCoordination();
        }

        $schedule = $compiledSchedule ?? MappedTableDependencies::compile($mappings);
        $orderedRowSets = [];
        foreach (array_keys($schedule) as $definition) {
            $rowSet = $rowSets[$definition];
            $rowSet->enrollInCoordinator();
            $orderedRowSets[$definition] = $rowSet;
        }

        $this->rowSets = $orderedRowSets;
        $this->adapters = $adapters;
        $this->mappings = $mappings;
        $this->referenced = $schedule;
    }

    public function hasPendingChanges(): bool
    {
        return array_any($this->rowSets, static fn(MappedRowSet $rowSet): bool => $rowSet->hasPendingChanges());
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
        $resource = null;
        foreach (array_keys($plans) as $definition) {
            $active = $this->adapters[$definition]->activeTransactionResource();
            if (null !== $resource && $resource !== $active) {
                throw new \LogicException('Coordinated mapped row sets must use the same transaction resource.');
            }
            $resource = $active;
        }

        foreach ($plans as $definition => $plan) {
            $this->adapters[$definition]->check($plan);
        }

        foreach (array_reverse($plans, preserve_keys: true) as $definition => $plan) {
            $adapter = $this->adapters[$definition];
            $adapter->writeRows(array_values(array_filter(
                $plan->rowUpdates,
                static fn(RowUpdatePlan $row): bool => RowUpdatePlan::MODE_DELETE === $row->mode,
            )));
            $adapter->deleteExcludedRows($plan);
        }

        foreach ($plans as $definition => $plan) {
            $this->adapters[$definition]->writeRows(array_values(array_filter(
                $plan->rowUpdates,
                static fn(RowUpdatePlan $row): bool => $row->writes() && RowUpdatePlan::MODE_DELETE !== $row->mode,
            )));
        }

        foreach (array_keys($plans) as $definition) {
            $this->rowSets[$definition]->clear();
        }
    }

    public function clear(): void
    {
        foreach ($this->rowSets as $rowSet) {
            $rowSet->clear();
        }
    }

    /** @param array<class-string<MappedRowSetDefinition>, RowSetUpdatePlan> $plans */
    private function assertSupported(array $plans): void
    {
        $absent = [];
        $retainedByOwner = [];
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
            foreach ($plan->ownerCollections as $collection) {
                if (null === $collection->retainedRowKeys) {
                    continue;
                }

                $retainedByOwner[$definition][$collection->owner->fingerprint()] = array_fill_keys(array_map(
                    static fn(MappedKey $key): string => $key->fingerprint(),
                    $collection->retainedRowKeys,
                ), true);
            }
        }

        foreach ($plans as $definition => $plan) {
            $mapping = $this->mappings[$definition];
            $owner = $mapping->owner;
            if (
                null === $owner
                || !isset($absent[$owner->definition]) && !isset($retainedByOwner[$owner->definition])
            ) {
                continue;
            }

            $parent = $this->mappings[$owner->definition];
            $check = static function (MappedKey $childKey) use ($owner, $parent, $absent, $retainedByOwner): void {
                $parentKey = $owner->parentKey($childKey);
                $fingerprint = $parentKey->fingerprint();
                $removed = isset($absent[$owner->definition][$fingerprint]);
                $parentCollections = $retainedByOwner[$owner->definition] ?? [];
                if ([] !== $parentCollections) {
                    $ownerKey = $parent->ownerKey($parentKey);
                    $retained = $parentCollections[$ownerKey->fingerprint()] ?? null;
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
            foreach ($plan->ownerCollections as $collection) {
                foreach ($collection->retainedRowKeys ?? [] as $key) {
                    $check($key);
                }
            }
        }
    }
}
