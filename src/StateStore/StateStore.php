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
    public function load(string $stateClass, int|string $id): ?object
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $row = $this->tableRegistry->tableFor($metadata)->row($id, $metadata->selectedColumns());

        if (false === $row) {
            return null;
        }

        $payload = $metadata->payloadFromRow($id, $row);
        foreach ($metadata->collections as $collection) {
            $payload[$collection->property] = $this->loadCollection($collection, $id);
        }

        return $this->normalizer->fromScalar($payload, $stateClass);
    }

    public function save(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $id = $metadata->identifierFromNormalized($normalizedState);

        $this->tableRegistry->tableFor($metadata)
            ->withId($id)
            ->upsert()
            ->setColumns($metadata->columnsFromNormalized($normalizedState))
        ;

        foreach ($metadata->collections as $collection) {
            $this->saveCollection($collection, $id, $this->normalizedCollectionItems($metadata, $normalizedState, $collection));
        }
    }

    public function expect(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $id = $metadata->identifierFromNormalized($normalizedState);
        $expectedColumns = $metadata->optimisticColumnsFromNormalized($normalizedState);

        if ([] !== $expectedColumns) {
            $this->tableRegistry->tableFor($metadata)
                ->withId($id)
                ->expectColumnValues($expectedColumns)
            ;
        }

        foreach ($metadata->collections as $collection) {
            $items = $this->normalizedCollectionItems($metadata, $normalizedState, $collection);
            $this->tableRegistry->collectionFor($collection, $id)
                ->expectSameRowIds(array_map(
                    fn (array $item): int|string => $collection->identifierFromNormalized($item),
                    $items,
                ))
            ;
        }
    }

    /**
     * Buffer an optimistic expectation that the target row does not exist yet.
     *
     * The expectation stays inert until a later write targets the same mapped row.
     *
     * @template T of object
     *
     * @param class-string<T> $stateClass
     */
    public function expectMissing(string $stateClass, int|string $id): void
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);

        $this->tableRegistry->tableFor($metadata)
            ->withId($id)
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

    private function loadCollection(StateCollectionMetadata $collection, int|string $ownerId): array
    {
        $items = [];
        foreach ($this->tableRegistry->collectionFor($collection, $ownerId)->rows($collection->selectedColumns()) as $rowId => $row) {
            $items[] = $collection->payloadFromRow($rowId, $row);
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function saveCollection(StateCollectionMetadata $collection, int|string $ownerId, array $items): void
    {
        $rowSet = $this->tableRegistry->collectionFor($collection, $ownerId);
        $desiredIds = [];

        foreach ($items as $item) {
            $id = $collection->identifierFromNormalized($item);
            $desiredIds[(string) $id] = true;
            $rowSet->withId($id)
                ->upsert()
                ->setColumns($collection->columnsFromNormalized($item))
            ;
        }

        foreach (array_keys($rowSet->rows()) as $existingId) {
            if (!array_key_exists($existingId, $desiredIds)) {
                $rowSet->withId($existingId)->delete();
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
}
