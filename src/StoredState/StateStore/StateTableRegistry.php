<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\UnitOfWork\Exception\ConcurrencyException;
use Ultra\UnitOfWork\UnitOfWork;

final class StateTableRegistry implements UnitOfWork
{
    /** @var array<string, MappedRowSet> */
    private array $tables;

    /** @var array<string, MappedRowSet> */
    private array $collectionTables = [];

    /** @var array<string, true> */
    private array $collectionDeletions = [];

    /** @var array<string, array<string, true>> */
    private array $deletedStates = [];

    /** @var list<array{query: \Closure, expected: mixed, name: ?string}> */
    private array $queryExpectations = [];

    /** @var array<string, array<string, MappedKey>> */
    private array $locks = [];

    /**
     * @param array<string, MappedRowSet> $tables
     */
    public function __construct(
        array $tables,
        private ?MappedRowSetAdapterFactory $adapterFactory = null,
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
        return $this->legacyCollection($collection, $collection->ownerKey($normalizedOwnerId));
    }

    /** @return list<MappedRow> */
    public function rowsForCollection(StateCollectionMetadata $collection, MappedKey $owner): array
    {
        return $this->legacyCollection($collection, $owner)->rows($collection->selectedColumns());
    }

    /** @param list<MappedKey> $keys */
    public function expectCollection(StateCollectionMetadata $collection, MappedKey $owner, array $keys): void
    {
        $this->legacyCollection($collection, $owner)->expectSameRowKeys($keys);
    }

    /** @param iterable<MappedRow> $rows */
    public function reconcileCollection(StateCollectionMetadata $collection, MappedKey $owner, iterable $rows): void
    {
        $this->legacyCollection($collection, $owner)->reconcileRows($rows);
    }

    private function legacyCollection(StateCollectionMetadata $collection, MappedKey $scope): MappedRowSet
    {
        if (null === $this->adapterFactory) {
            throw new \LogicException(sprintf(
                'No mapped row set adapter factory was registered for state collection "%s".',
                $collection->property,
            ));
        }

        $key = $collection->tableKey . '#' . $scope->fingerprint();

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

    /** A deleted owner cannot be saved again until its pending deletion is flushed or cleared. */
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
        $this->clear();
    }

    private function acquireGuards(): void
    {
        if ([] === $this->locks) {
            return;
        }
        if (null === $this->guardLockAdapter) {
            throw new \LogicException('State store guards require a GuardLockAdapter using the flush transaction.');
        }

        ksort($this->locks, SORT_STRING);
        foreach ($this->locks as $tableName => $keys) {
            ksort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $this->guardLockAdapter->acquire($tableName . ':manual', $key->fingerprint(), GuardLockMode::EXCLUSIVE);
            }
        }
    }

    /** Register a cooperative guard on a physical row identity, including an absent row. */
    public function lock(string $tableName, MappedKey $key): void
    {
        $this->locks[$tableName][$key->fingerprint()] = $key;
    }

    public function hasPendingChanges(): bool
    {
        if ([] !== $this->queryExpectations || [] !== $this->locks) {
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
        $this->collectionDeletions = [];
        $this->deletedStates = [];
        $this->queryExpectations = [];
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
