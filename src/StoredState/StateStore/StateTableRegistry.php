<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\RowSetUpdatePlan;
use Ultra\StateStore\Exception\PendingQueryChangesException;
use Ultra\StateStore\Exception\UnknownQueryGuardException;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;

final class StateTableRegistry implements UnitOfWork
{
    /** @var array<string, MappedRowSet> */
    private array $tables;

    /** @var array<string, MappedRowSet> */
    private array $collectionTables = [];

    /** @var array<string, array{tableKey: string, scope: MappedKey}> */
    private array $collectionScopes = [];

    /** @var array<string, true> */
    private array $collectionDeletions = [];

    /** @var array<string, array<string, true>> */
    private array $deletedStates = [];

    /** @var list<array{query: \Closure, expected: mixed, name: ?string}> */
    private array $queryExpectations = [];

    /** @var array<string, array<array-key, GuardLockMode>> Numeric guard values become integer keys. */
    private array $guards = [];

    /** @var array<string, array<string, MappedKey>> */
    private array $locks = [];

    /**
     * @param array<string, MappedRowSet> $tables
     * @param array<string, list<array{tableKey: string, keyBy: string, columns: list<string>|null}>> $queryGuardDefinitions
     */
    public function __construct(
        array $tables,
        private ?MappedRowSetAdapterFactory $adapterFactory = null,
        private array $queryGuardDefinitions = [],
        private ?GuardLockAdapter $guardLockAdapter = null,
    ) {
        $this->tables = $tables;
    }

    public function tableFor(StateMetadata $metadata): MappedRowSet
    {
        $table = $this->tables[$metadata->tableKey] ?? null;
        if (!$table instanceof MappedRowSet) {
            throw new \LogicException(sprintf(
                'No mapped row set service was registered for state "%s".',
                $metadata->stateClass,
            ));
        }

        return $table;
    }

    public function collectionFor(StateCollectionMetadata $collection, mixed $normalizedOwnerId): MappedRowSet
    {
        if (null === $this->adapterFactory) {
            throw new \LogicException(sprintf(
                'No mapped row set adapter factory was registered for state collection "%s".',
                $collection->property,
            ));
        }

        $scope = $collection->ownerKey($normalizedOwnerId);
        $key = $collection->tableKey . '#' . $scope->fingerprint();
        $this->collectionScopes[$key] = ['tableKey' => $collection->tableKey, 'scope' => $scope];

        return $this->collectionTables[$key] ??= new MappedRowSet($this->adapterFactory->createScoped(
            $collection->tableName,
            $collection->keyColumns(),
            $scope,
        ));
    }

    /** Buffer owned collections with the root deletion so flush can remove children before their parent. */
    public function delete(StateMetadata $metadata, mixed $normalizedId): void
    {
        $key = $metadata->keyFromNormalizedId($normalizedId);
        $this->tableFor($metadata)->withKey($key)->delete();
        $this->deletedStates[$metadata->tableKey][$key->fingerprint()] = true;
        foreach ($metadata->collections as $collection) {
            $this->collectionFor($collection, $normalizedId)->reconcileRows([]);
            $collectionKey = $collection->tableKey . '#' . $collection->ownerKey($normalizedId)->fingerprint();
            $this->collectionDeletions[$collectionKey] = true;
        }
    }

    /** A deleted owner cannot be saved again until this unit of work is cleared. */
    public function assertCanSave(StateMetadata $metadata, MappedKey $key): void
    {
        if (isset($this->deletedStates[$metadata->tableKey][$key->fingerprint()])) {
            throw new ConcurrencyException(sprintf(
                'Cannot save state "%s", row "%s" because it is deleted in this unit of work.',
                $metadata->stateClass,
                (string) $key,
            ));
        }
    }

    public function flush(): void
    {
        $plans = $this->updatePlans();
        foreach (QueryGuardWriteRules::requests(
            $plans,
            $this->queryGuardDefinitions,
            $this->reconciliationScopes(),
        ) as $request) {
            $this->guard($request['name'], $request['value'], GuardLockMode::EXCLUSIVE);
        }
        $this->acquireGuards();
        foreach ($this->queryExpectations as $expectation) {
            if ($expectation['query']() !== $expectation['expected']) {
                throw new ConcurrencyException(sprintf(
                    'Query expectation failed%s.',
                    null === $expectation['name'] ? '' : ' for "' . $expectation['name'] . '"',
                ));
            }
        }
        $beforeDeletes = array_intersect_key($this->collectionTables, $this->collectionDeletions);
        foreach ([
            ...$beforeDeletes,
            ...$this->tables,
            ...array_diff_key($this->collectionTables, $beforeDeletes),
        ] as $table) {
            $table->flush();
        }
    }

    /** Check only the initial decision read. Flush replays the recorded callback before applying local writes. */
    public function assertQueryCanBeObserved(string $name, int|string $value): void
    {
        $scopes = $this->queryGuardScopes($name);
        foreach (QueryGuardWriteRules::requests(
            $this->updatePlans(),
            [$name => $scopes],
            $this->reconciliationScopes(),
        ) as $request) {
            if ((string) $request['value'] === (string) $value) {
                throw new PendingQueryChangesException($name, $value, $request['tableKey'], $request['key']);
            }
        }
    }

    /** @return list<array{tableKey: string, keyBy: string, columns: list<string>|null}> */
    private function queryGuardScopes(string $name): array
    {
        $scopes = $this->queryGuardDefinitions[$name] ?? [];
        if ([] === $scopes) {
            throw new UnknownQueryGuardException($name);
        }

        return $scopes;
    }

    /** @return array<string, RowSetUpdatePlan> */
    private function updatePlans(): array
    {
        $plans = array_map(static fn(MappedRowSet $table): RowSetUpdatePlan => $table->updatePlan(), $this->tables);
        foreach ($this->collectionTables as $key => $table) {
            $tableKey = $this->collectionScopes[$key]['tableKey'];
            $previous = $plans[$tableKey] ?? null;
            $plans[$tableKey] = new RowSetUpdatePlan(null, [
                ...($previous->rowUpdates ?? []),
                ...$table->updatePlan()->rowUpdates,
            ]);
        }

        return $plans;
    }

    /** @return array<string, list<MappedKey>> */
    private function reconciliationScopes(): array
    {
        $scopes = [];
        foreach ($this->collectionTables as $key => $table) {
            if (null === $table->updatePlan()->retainedRowKeys) {
                continue;
            }

            $context = $this->collectionScopes[$key];
            $scopes[$context['tableKey']][] = $context['scope'];
        }

        return $scopes;
    }

    private function acquireGuards(): void
    {
        if ([] === $this->guards && [] === $this->locks) {
            return;
        }
        if (null === $this->guardLockAdapter) {
            throw new \LogicException('State store guards require a GuardLockAdapter using the flush transaction.');
        }

        // Consolidate modes before acquiring anything: an exclusive request must never upgrade a held lock.
        ksort($this->guards, SORT_STRING);
        foreach ($this->guards as $name => $values) {
            ksort($values, SORT_STRING);
            foreach ($values as $value => $mode) {
                $this->guardLockAdapter->acquire('ultra:query_guard:' . $name, (string) $value, $mode);
            }
        }
        ksort($this->locks, SORT_STRING);
        foreach ($this->locks as $tableName => $keys) {
            ksort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $this->guardLockAdapter->acquire($tableName . ':manual', $key->fingerprint(), GuardLockMode::EXCLUSIVE);
            }
        }
    }

    /** Buffer a named guard; exclusive dominates shared for the same name/value. */
    public function guard(string $name, int|string $value, GuardLockMode $mode): void
    {
        $this->queryGuardScopes($name);
        $fingerprint = (string) $value;
        if (GuardLockMode::EXCLUSIVE !== ($this->guards[$name][$fingerprint] ?? null)) {
            $this->guards[$name][$fingerprint] = $mode;
        }
    }

    /** Register a cooperative guard on a physical row identity, including an absent row. */
    public function lock(string $tableName, MappedKey $key): void
    {
        $this->locks[$tableName][$key->fingerprint()] = $key;
    }

    public function hasPendingChanges(): bool
    {
        if ([] !== $this->queryExpectations || [] !== $this->guards || [] !== $this->locks) {
            return true;
        }

        foreach ([...$this->tables, ...$this->collectionTables] as $table) {
            if ($table->hasPendingChanges()) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        foreach ([...$this->tables, ...$this->collectionTables] as $table) {
            $table->clear();
        }

        $this->collectionTables = [];
        $this->collectionScopes = [];
        $this->collectionDeletions = [];
        $this->deletedStates = [];
        $this->queryExpectations = [];
        $this->guards = [];
        $this->locks = [];
    }

    /** @param callable(): mixed $query */
    public function expectQuery(callable $query, mixed $expected, ?string $name = null): void
    {
        self::assertQueryValue($expected);
        $this->queryExpectations[] = ['query' => $query(...), 'expected' => $expected, 'name' => $name];
    }

    private static function assertQueryValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertQueryValue($item);
            }

            return;
        }
        if (null !== $value && !is_scalar($value)) {
            throw new \InvalidArgumentException('Query expectations require scalars, null or arrays of these values.');
        }
    }
}
