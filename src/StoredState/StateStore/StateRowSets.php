<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\UnitOfWork\Exception\ConcurrencyException;

/** @internal The coordinated row sets used by StateStore views. */
final readonly class StateRowSets
{
    /** @param array<string, MappedRowSet> $tables Row sets indexed by physical table name. */
    public function __construct(
        private array $tables,
        private MappedTableUnitOfWork $unitOfWork,
    ) {}

    public function tableFor(StateMetadata $metadata): MappedRowSet
    {
        return $this->tables[$metadata->tableName];
    }

    /** @return list<MappedRow> */
    public function rowsForCollection(StateCollectionMetadata $collection, MappedKey $owner): array
    {
        return $this->tables[$collection->tableName]->rowsForOwner($owner, $collection->readColumns);
    }

    /** @param list<MappedKey> $keys */
    public function expectCollection(StateCollectionMetadata $collection, MappedKey $owner, array $keys): void
    {
        $this->tables[$collection->tableName]->expectSameRowKeysForOwner($owner, $keys);
    }

    /** @param iterable<MappedRow> $rows */
    public function reconcileCollection(StateCollectionMetadata $collection, MappedKey $owner, iterable $rows): void
    {
        $this->tables[$collection->tableName]->reconcileForOwner($owner, $rows);
    }

    public function assertCanSave(StateMetadata $metadata, MappedKey $key): void
    {
        if ($this->tableFor($metadata)->hasPendingDeletion($key)) {
            throw new ConcurrencyException(sprintf(
                'Cannot save state "%s", row "%s" because it is deleted in this unit of work.',
                $metadata->stateClass,
                (string) $key,
            ));
        }
    }

    public function delete(StateMetadata $metadata, mixed $normalizedId): void
    {
        $this->tableFor($metadata)->withKey($metadata->keyFromNormalizedId($normalizedId))->delete();
        foreach ($metadata->collections as $collection) {
            $this->reconcileCollection($collection, $collection->ownerKey($normalizedId), []);
        }
    }

    public function lock(string $table, MappedKey $key): void
    {
        $this->unitOfWork->lockRow($table, $key);
    }

    /** @param callable(): mixed $query */
    public function expectQuery(callable $query, mixed $expected, ?string $name = null): void
    {
        $this->unitOfWork->expectQuery($query, $expected, $name);
    }
}
