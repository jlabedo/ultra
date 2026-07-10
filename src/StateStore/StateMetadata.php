<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedKey;

/**
 * @phpstan-type StateMetadataDefinition array{
 *     stateClass: class-string,
 *     tableName: string,
 *     tableKey: string,
 *     idProperty: string,
 *     idType: string,
 *     idScalar: bool,
 *     idPropertyColumns: array<string, string>,
 *     propertyColumns: array<string, string>,
 *     optimisticPropertyColumns: array<string, string>,
 *     embedded: array<string, StateEmbeddedMetadataDefinition>,
 *     collections: array<string, StateCollectionMetadataDefinition>
 * }
 */
final readonly class StateMetadata
{
    /**
     * @param class-string                            $stateClass
     * @param array<string, string>                   $idPropertyColumns
     * @param array<string, string>                   $propertyColumns
     * @param array<string, string>                   $optimisticPropertyColumns
     * @param array<string, StateEmbeddedMetadata>    $embedded
     * @param array<string, StateCollectionMetadata>  $collections
     */
    public function __construct(
        public string $stateClass,
        public string $tableName,
        public string $tableKey,
        public string $idProperty,
        public string $idType,
        public bool $idScalar,
        public array $idPropertyColumns,
        public array $propertyColumns,
        public array $optimisticPropertyColumns,
        public array $embedded = [],
        public array $collections = [],
    ) {}

    /** @param StateMetadataDefinition $definition */
    public static function fromArray(array $definition): self
    {
        return new self(
            stateClass: $definition['stateClass'],
            tableName: $definition['tableName'],
            tableKey: $definition['tableKey'],
            idProperty: $definition['idProperty'],
            idType: $definition['idType'],
            idScalar: $definition['idScalar'],
            idPropertyColumns: $definition['idPropertyColumns'],
            propertyColumns: $definition['propertyColumns'],
            optimisticPropertyColumns: $definition['optimisticPropertyColumns'],
            embedded: array_map(StateEmbeddedMetadata::fromArray(...), $definition['embedded'] ?? []),
            collections: array_map(StateCollectionMetadata::fromArray(...), $definition['collections'] ?? []),
        );
    }

    /** @return StateMetadataDefinition */
    public function toArray(): array
    {
        return [
            'stateClass' => $this->stateClass,
            'tableName' => $this->tableName,
            'tableKey' => $this->tableKey,
            'idProperty' => $this->idProperty,
            'idType' => $this->idType,
            'idScalar' => $this->idScalar,
            'idPropertyColumns' => $this->idPropertyColumns,
            'propertyColumns' => $this->propertyColumns,
            'optimisticPropertyColumns' => $this->optimisticPropertyColumns,
            'embedded' => array_map(static fn (StateEmbeddedMetadata $embedded): array => $embedded->toArray(), $this->embedded),
            'collections' => array_map(static fn (StateCollectionMetadata $collection): array => $collection->toArray(), $this->collections),
        ];
    }

    /** @return list<string> */
    public function keyColumns(): array
    {
        $columns = array_values($this->idPropertyColumns);
        sort($columns, SORT_STRING);

        return $columns;
    }

    /** @return list<string> */
    public function selectedColumns(): array
    {
        $columns = array_values($this->propertyColumns);
        foreach ($this->embedded as $embedded) {
            $columns = [...$columns, ...$embedded->selectedColumns()];
        }

        return array_values(array_unique($columns));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function payloadFromRow(mixed $normalizedId, array $row): array
    {
        $payload = [$this->idProperty => $normalizedId];
        foreach ($this->propertyColumns as $property => $column) {
            $payload[$property] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        foreach ($this->embedded as $embedded) {
            $payload[$embedded->property] = $embedded->payloadFromRow($row);
        }

        return $payload;
    }

    /** @param array<string, mixed> $normalizedState */
    public function keyFromNormalizedState(array $normalizedState): MappedKey
    {
        return $this->keyFromNormalizedId($this->requireNormalizedValue($normalizedState, $this->idProperty));
    }

    public function keyFromNormalizedId(mixed $normalizedId): MappedKey
    {
        if ($this->idScalar) {
            if (!is_int($normalizedId) && !is_string($normalizedId)) {
                throw new \LogicException(sprintf('State "%s" id must normalize to int|string, got %s.', $this->stateClass, get_debug_type($normalizedId)));
            }

            return new MappedKey([array_values($this->idPropertyColumns)[0] => $normalizedId]);
        }

        if (!is_array($normalizedId)) {
            throw new \LogicException(sprintf('State "%s" composite id must normalize to an array, got %s.', $this->stateClass, get_debug_type($normalizedId)));
        }
        $expectedFields = array_keys($this->idPropertyColumns);
        $actualFields = array_keys($normalizedId);
        sort($expectedFields, SORT_STRING);
        sort($actualFields, SORT_STRING);
        if ($expectedFields !== $actualFields) {
            throw new \LogicException(sprintf('State "%s" composite id normalized fields do not match compiled fields.', $this->stateClass));
        }

        $values = [];
        foreach ($this->idPropertyColumns as $property => $column) {
            $value = $normalizedId[$property];
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException(sprintf('State "%s" id component "%s" must normalize to int|string, got %s.', $this->stateClass, $property, get_debug_type($value)));
            }
            $values[$column] = $value;
        }

        return new MappedKey($values);
    }

    public function normalizedIdFromKey(MappedKey $key): int|string|array
    {
        $key->assertColumns($this->keyColumns());
        if ($this->idScalar) {
            return $key->value(array_values($this->idPropertyColumns)[0]);
        }

        $normalized = [];
        foreach ($this->idPropertyColumns as $property => $column) {
            $normalized[$property] = $key->value($column);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $normalizedState @return array<string, mixed> */
    public function columnsFromNormalized(array $normalizedState): array
    {
        $columns = [];
        foreach ($this->propertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedValue($normalizedState, $property);
        }
        foreach ($this->embedded as $embedded) {
            $columns = [...$columns, ...$embedded->columnsFromNormalized($normalizedState)];
        }

        return $columns;
    }

    /** @param array<string, mixed> $normalizedState @return array<string, mixed> */
    public function optimisticColumnsFromNormalized(array $normalizedState): array
    {
        $columns = [];
        foreach ($this->optimisticPropertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedValue($normalizedState, $property);
        }
        foreach ($this->embedded as $embedded) {
            $columns = [...$columns, ...$embedded->optimisticColumnsFromNormalized($normalizedState)];
        }

        return $columns;
    }

    /** @param array<string, mixed> $normalizedState */
    private function requireNormalizedValue(array $normalizedState, string $property): mixed
    {
        if (!array_key_exists($property, $normalizedState)) {
            throw new \LogicException(sprintf('State "%s" is missing normalized property "%s".', $this->stateClass, $property));
        }

        return $normalizedState[$property];
    }
}
