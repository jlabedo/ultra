<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\Mapper\UltraNormalizer;

final readonly class StateStore
{
    public function __construct(
        private StateMetadataRegistry $metadataRegistry,
        private StateTableRegistry $tableRegistry,
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
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $this->assertIdentifierType($metadata, $id);
        $normalizedId = is_object($id) ? $this->normalizer->toScalar($id) : $id;
        $key = $metadata->keyFromNormalizedId($normalizedId);
        $row = $this->tableRegistry->tableFor($metadata)->row($key, $metadata->selectedColumns());

        if (false === $row) {
            return null;
        }

        $payload = $metadata->payloadFromRow($normalizedId, $row);
        foreach ($metadata->collections as $collection) {
            $payload[$collection->property] = $this->loadCollection($collection, $normalizedId);
        }

        return $this->normalizer->fromScalar($payload, $stateClass);
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
        $state = $this->load($stateClass, $id);
        if (null === $state) {
            $this->expectMissing($stateClass, $id);
        } else {
            $this->expect($state);
        }

        return $state;
    }

    public function save(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $key = $metadata->keyFromNormalizedState($normalizedState);
        $normalizedId = $normalizedState[$metadata->idProperty];

        $this->tableRegistry->tableFor($metadata)
            ->withKey($key)
            ->upsert()
            ->setColumns($metadata->columnsFromNormalized($normalizedState))
        ;

        foreach ($metadata->collections as $collection) {
            $this->saveCollection($collection, $normalizedId, $this->normalizedCollectionItems($metadata, $normalizedState, $collection));
        }
    }

    /**
     * Register a cooperative guard on this state's mapped row for the flush transaction.
     * Every action affecting the guarded invariant must request the same guard.
     */
    public function lock(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $this->tableRegistry->tableFor($metadata)->lock($metadata->keyFromNormalizedState($normalizedState));
    }

    public function expect(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $key = $metadata->keyFromNormalizedState($normalizedState);
        $normalizedId = $normalizedState[$metadata->idProperty];
        $expectedColumns = $metadata->optimisticColumnsFromNormalized($normalizedState);

        $this->tableRegistry->tableFor($metadata)
            ->withKey($key)
            ->expectPresent()
            ->expectColumnValues($expectedColumns)
        ;

        foreach ($metadata->collections as $collection) {
            $items = $this->normalizedCollectionItems($metadata, $normalizedState, $collection);
            $rowSet = $this->tableRegistry->collectionFor($collection, $normalizedId);
            $ownerKey = $collection->ownerKey($normalizedId);
            $rowSet->expectSameRowKeys(array_map(
                fn (array $item) => $collection->itemKey($item, $ownerKey),
                $items,
            ))
            ;
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
        $this->tableRegistry->expectQuery($query, $expected, $name);
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
        $this->tableRegistry->tableFor($metadata)
            ->withKey($metadata->keyFromNormalizedId($normalizedId))
            ->expectAbsent()
        ;
    }

    /**
     * @return array{StateMetadata, array<string, mixed>}
     */
    private function normalizeState(object $state): array
    {
        $metadata = $this->metadataRegistry->metadataFor($state::class);
        $normalizedState = $this->normalizer->toScalar($state);
        if (!is_array($normalizedState)) {
            throw new \LogicException(sprintf(
                'State "%s" must normalize to an array.',
                $metadata->stateClass,
            ));
        }

        return [$metadata, $normalizedState];
    }

    private function loadCollection(StateCollectionMetadata $collection, mixed $normalizedOwnerId): array
    {
        $items = [];
        foreach ($this->tableRegistry->collectionFor($collection, $normalizedOwnerId)->rows($collection->selectedColumns()) as $row) {
            $items[] = $collection->payloadFromRow($row);
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function saveCollection(StateCollectionMetadata $collection, mixed $normalizedOwnerId, array $items): void
    {
        $rowSet = $this->tableRegistry->collectionFor($collection, $normalizedOwnerId);
        $ownerKey = $collection->ownerKey($normalizedOwnerId);
        $desiredIds = [];

        foreach ($items as $item) {
            $key = $collection->itemKey($item, $ownerKey);
            $desiredIds[$key->fingerprint()] = true;
            $rowSet->withKey($key)
                ->upsert()
                ->setColumns($collection->columnsFromNormalized($item))
            ;
        }

        foreach ($rowSet->rows() as $existingRow) {
            if (!array_key_exists($existingRow->key->fingerprint(), $desiredIds)) {
                $rowSet->withKey($existingRow->key)->delete();
            }
        }
    }

    /**
     * @param array<string, mixed> $normalizedState
     *
     * @return list<array<string, mixed>>
     */
    private function normalizedCollectionItems(StateMetadata $metadata, array $normalizedState, StateCollectionMetadata $collection): array
    {
        if (!array_key_exists($collection->property, $normalizedState) || !is_array($normalizedState[$collection->property])) {
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
