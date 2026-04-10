<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\Mapper\UltraNormalizer;
use Ultra\StateStore\Exception\StateNotFoundException;

final readonly class StateStore
{
    public function __construct(
        private StateMetadataRegistry $metadataRegistry,
        private StateTableRegistry $tableRegistry,
        private UltraNormalizer $normalizer,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $stateClass
     * @return T
     */
    public function load(string $stateClass, string|int $id): object
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);
        $row = $this->tableRegistry->tableFor($metadata)->row($id, $metadata->selectedColumns());

        if (false === $row) {
            throw new StateNotFoundException($stateClass, $id);
        }

        return $this->normalizer->fromScalar($metadata->payloadFromRow($row), $stateClass);
    }

    public function save(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $id = $metadata->identifierFromNormalized($normalizedState);

        $this->tableRegistry->tableFor($metadata)
            ->withId($id)
            ->upsert()
            ->setColumns($metadata->columnsFromNormalized($normalizedState));
    }

    public function expect(object $state): void
    {
        [$metadata, $normalizedState] = $this->normalizeState($state);
        $id = $metadata->identifierFromNormalized($normalizedState);
        $expectedColumns = $metadata->optimisticColumnsFromNormalized($normalizedState);

        if ([] === $expectedColumns) {
            return;
        }

        $this->tableRegistry->tableFor($metadata)
            ->withId($id)
            ->expectColumnValues($expectedColumns);
    }

    /**
     * Buffer an optimistic expectation that the target row does not exist yet.
     *
     * The expectation stays inert until a later write targets the same mapped row.
     *
     * @template T of object
     * @param class-string<T> $stateClass
     */
    public function expectMissing(string $stateClass, string|int $id): void
    {
        $metadata = $this->metadataRegistry->metadataFor($stateClass);

        $this->tableRegistry->tableFor($metadata)
            ->withId($id)
            ->expectAbsent();
    }

    /**
     * @return array{StateMetadata, array<string, mixed>}
     */
    private function normalizeState(object $state): array
    {
        $metadata = $this->metadataRegistry->metadataFor($state::class);
        $normalizedState = $this->normalizer->toScalar($state);
        if (! is_array($normalizedState)) {
            throw new \LogicException(sprintf(
                'State "%s" must normalize to an array.',
                $metadata->stateClass,
            ));
        }

        return [$metadata, $normalizedState];
    }
}
