<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;
use Ultra\Mapper\UltraNormalizer;

final readonly class StateStore
{
    public function __construct(
        private StateMetadataRegistry $metadataRegistry,
        private StateRowSets $rowSets,
        private UltraNormalizer $normalizer,
    ) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $stateClass
     *
     * @return T|null
     */
    public function load(string $stateClass, int|string|object $id): ?object
    {
        return $this->read($stateClass, $id, observe: false);
    }

    /**
     * Read this unit of work's view and register its optimistic expectations for flush.
     * A missing state registers an absence expectation; no database lock is acquired during the read.
     *
     * @template T of object
     * @param class-string<T> $stateClass
     * @return T|null
     */
    public function observe(string $stateClass, int|string|object $id): ?object
    {
        return $this->read($stateClass, $id, observe: true);
    }

    /**
     * @template T of object
     * @param class-string<T> $stateClass
     * @return T|null
     */
    private function read(string $stateClass, int|string|object $id, bool $observe): ?object
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $this->assertIdentifierType($metadata, $id);
        $normalizedId = is_object($id) ? $this->normalizer->toScalar($id) : $id;
        $key = $metadata->keyFromNormalizedId($normalizedId);
        $handle = $this->rowSets->tableFor($metadata)->withKey($key);
        $columns = $metadata->selectedColumns();
        $row = $observe
            ? $handle->observeProjectedColumns($columns, $metadata->optimisticColumnSet)
            : $handle->get($columns);

        if (null === $row) {
            return null;
        }

        $payload = $metadata->payloadFromRow($normalizedId, $row);
        foreach ($metadata->collections as $collection) {
            $payload[$collection->property] = $this->loadCollection($collection, $normalizedId, $observe);
        }

        return $this->normalizer->fromScalar($payload, $stateClass);
    }

    /**
     * Read only the root row's presence, without hydration, collections or optimistic expectations.
     * Includes this unit of work's buffered inserts and deletions.
     *
     * @param class-string $stateClass
     */
    public function exists(string $stateClass, int|string|object $id): bool
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $this->assertIdentifierType($metadata, $id);
        $normalizedId = is_object($id) ? $this->normalizer->toScalar($id) : $id;
        $key = $metadata->keyFromNormalizedId($normalizedId);

        return null !== $this->rowSets->tableFor($metadata)->withKey($key)->get([]);
    }

    /**
     * Observe only the root row's presence in this unit of work's view.
     * Selects identifier columns without hydrating the state or loading its collections.
     * Presence or absence is checked at flush; column values and collections are not expected.
     *
     * @param class-string $stateClass
     */
    public function observeExists(string $stateClass, int|string|object $id): bool
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $this->assertIdentifierType($metadata, $id);
        $normalizedId = is_object($id) ? $this->normalizer->toScalar($id) : $id;
        $key = $metadata->keyFromNormalizedId($normalizedId);

        return null !== $this->rowSets->tableFor($metadata)->withKey($key)->observe([]);
    }

    public function save(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $key = $metadata->keyFromNormalizedState($normalizedState);
        $this->rowSets->assertCanSave($metadata, $key);
        $normalizedId = $normalizedState[$metadata->idProperty];

        $this->rowSets
            ->tableFor($metadata)
            ->withKey($key)
            ->upsert()
            ->setColumns($metadata->columnsFromNormalized($normalizedState));

        foreach ($metadata->collections as $collection) {
            $owner = $collection->ownerKey($normalizedId);
            $this->rowSets->reconcileCollection(
                $collection,
                $owner,
                $this->collectionRows(
                    $collection,
                    $owner,
                    $this->normalizedCollectionItems($metadata, $normalizedState, $collection),
                ),
            );
        }
    }

    /**
     * Buffer deletion of the root row and the collections declared by this state class.
     * Existing optimistic expectations are retained; no state or collection is read here.
     *
     * @param class-string $stateClass
     */
    public function delete(string $stateClass, int|string|object $id): void
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $this->assertIdentifierType($metadata, $id);
        $normalizedId = is_object($id) ? $this->normalizer->toScalar($id) : $id;
        $this->rowSets->delete($metadata, $normalizedId);
    }

    /**
     * Register a cooperative guard on this state's mapped row for the flush transaction.
     * Every action affecting the guarded invariant must request the same guard.
     */
    public function lock(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $this->rowSets->lock($metadata->tableName, $metadata->keyFromNormalizedState($normalizedState));
    }

    public function expect(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $key = $metadata->keyFromNormalizedState($normalizedState);
        $normalizedId = $normalizedState[$metadata->idProperty];
        $expectedColumns = $metadata->optimisticColumnsFromNormalized($normalizedState);

        $this->rowSets->tableFor($metadata)->withKey($key)->expectPresent()->expectColumnValues($expectedColumns);

        foreach ($metadata->collections as $collection) {
            $items = $this->normalizedCollectionItems($metadata, $normalizedState, $collection);
            $owner = $collection->ownerKey($normalizedId);
            $this->rowSets->expectCollection($collection, $owner, array_map(
                static fn(array $item) => $collection->itemKey($item, $owner),
                $items,
            ));
        }
    }

    /**
     * Read committed data now, then require the same result before writing at flush.
     *
     * @template T
     * @param callable(): T $query Read-only query using the flush transaction's connection.
     * @return T
     */
    public function observeQuery(callable $query, ?string $name = null): mixed
    {
        $result = $query();
        $this->expectQuery($query, $result, $name);

        return $result;
    }

    /**
     * The callback must return a stable scalar, null or array of these values, without reading buffered writes.
     * Comparison is strict; normalize SQL types and sort unordered results in the callback.
     *
     * @param callable(): mixed $query
     */
    public function expectQuery(callable $query, mixed $expected, ?string $name = null): void
    {
        $this->rowSets->expectQuery($query, $expected, $name);
    }

    /**
     * Buffer an optimistic expectation that the target row does not exist yet.
     *
     * The expectation is checked at flush, and a later save of the same mapped row becomes an insert.
     *
     * @template T of object
     *
     * @param class-string<T> $stateClass
     */
    public function expectMissing(string $stateClass, int|string|object $id): void
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);

        $this->assertIdentifierType($metadata, $id);
        $normalizedId = is_object($id) ? $this->normalizer->toScalar($id) : $id;
        $this->rowSets->tableFor($metadata)->withKey($metadata->keyFromNormalizedId($normalizedId))->expectAbsent();
    }

    /**
     * @return array{StateMetadata, array<array-key, mixed>}
     */
    private function normalizeState(object $state): array
    {
        $metadata = $this->metadataRegistry->metadataFor($state::class);
        $normalizedState = $this->normalizer->toScalar($state);
        if (!is_array($normalizedState)) {
            throw new \LogicException(sprintf('State "%s" must normalize to an array.', $metadata->stateClass));
        }

        return [$metadata, $normalizedState];
    }

    /** @return list<array<string, mixed>> */
    private function loadCollection(StateCollectionMetadata $collection, mixed $normalizedOwnerId, bool $observe): array
    {
        $owner = $collection->ownerKey($normalizedOwnerId);
        $rows = $this->rowSets->rowsForCollection($collection, $owner);
        if ($observe) {
            $this->rowSets->expectCollection($collection, $owner, array_column($rows, 'key'));
        }

        return array_map($collection->payloadFromRow(...), $rows);
    }

    /**
     * @param list<array<array-key, mixed>> $items
     * @return iterable<MappedRow>
     */
    private function collectionRows(StateCollectionMetadata $collection, MappedKey $ownerKey, array $items): iterable
    {
        foreach ($items as $item) {
            yield new MappedRow($collection->itemKey($item, $ownerKey), $collection->columnsFromNormalized($item));
        }
    }

    /**
     * @param array<array-key, mixed> $normalizedState
     *
     * @return list<array<array-key, mixed>>
     */
    private function normalizedCollectionItems(
        StateMetadata $metadata,
        array $normalizedState,
        StateCollectionMetadata $collection,
    ): array {
        if (
            !array_key_exists($collection->property, $normalizedState)
            || !is_array($normalizedState[$collection->property])
        ) {
            throw new \LogicException(sprintf(
                'State "%s" collection "%s" must normalize to an array.',
                $metadata->stateClass,
                $collection->property,
            ));
        }

        $items = [];
        foreach ($normalizedState[$collection->property] as $item) {
            if (is_object($item)) {
                $item = $this->normalizer->toScalar($item);
            }

            if (!is_array($item)) {
                throw new \LogicException(sprintf(
                    'State "%s" collection "%s" items must normalize to arrays.',
                    $metadata->stateClass,
                    $collection->property,
                ));
            }

            $items[] = $item;
        }

        return $items;
    }

    private function assertIdentifierType(StateMetadata $metadata, int|string|object $id): void
    {
        $matches = match ($metadata->idType) {
            'int' => is_int($id),
            'string' => is_string($id),
            default => is_object($id) && is_a($id, $metadata->idType),
        };
        if (!$matches) {
            throw new \InvalidArgumentException(sprintf(
                'State "%s" expects identifier type %s, got %s.',
                $metadata->stateClass,
                $metadata->idType,
                get_debug_type($id),
            ));
        }
    }
}
