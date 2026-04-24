<?php

declare(strict_types=1);

namespace Ultra\StateStore;

/**
 * @phpstan-type StateMetadataDefinition array{
 *     stateClass: class-string,
 *     tableName: string,
 *     tableKey: string,
 *     idProperty: string,
 *     idColumn: string,
 *     propertyColumns: array<string, string>,
 *     optimisticPropertyColumns: array<string, string>
 * }
 */
final readonly class StateMetadata
{
    /**
     * @param array<string, string> $propertyColumns
     * @param array<string, string> $optimisticPropertyColumns
     */
    public function __construct(
        public string $stateClass,
        public string $tableName,
        public string $tableKey,
        public string $idProperty,
        public string $idColumn,
        public array $propertyColumns,
        public array $optimisticPropertyColumns,
    ) {}

    /**
     * @param StateMetadataDefinition $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            stateClass: $definition['stateClass'],
            tableName: $definition['tableName'],
            tableKey: $definition['tableKey'],
            idProperty: $definition['idProperty'],
            idColumn: $definition['idColumn'],
            propertyColumns: $definition['propertyColumns'],
            optimisticPropertyColumns: $definition['optimisticPropertyColumns'],
        );
    }

    /**
     * @return StateMetadataDefinition
     */
    public function toArray(): array
    {
        return [
            'stateClass' => $this->stateClass,
            'tableName' => $this->tableName,
            'tableKey' => $this->tableKey,
            'idProperty' => $this->idProperty,
            'idColumn' => $this->idColumn,
            'propertyColumns' => $this->propertyColumns,
            'optimisticPropertyColumns' => $this->optimisticPropertyColumns,
        ];
    }

    /**
     * @return list<string>
     */
    public function selectedColumns(): array
    {
        return array_values(array_unique(array_values($this->propertyColumns)));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function payloadFromRow(array $row): array
    {
        $payload = [
            $this->idProperty => $row[$this->idColumn],
        ];

        foreach ($this->propertyColumns as $property => $column) {
            $payload[$property] = array_key_exists($column, $row) ? $row[$column] : null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedState
     */
    public function identifierFromNormalized(array $normalizedState): int|string
    {
        $id = $this->requireNormalizedValue($normalizedState, $this->idProperty);

        if (!is_string($id) && !is_int($id)) {
            throw new \LogicException(sprintf(
                'State "%s" normalized id "%s" must be a string or int.',
                $this->stateClass,
                $this->idProperty,
            ));
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $normalizedState
     *
     * @return array<string, mixed>
     */
    public function columnsFromNormalized(array $normalizedState): array
    {
        $columns = [];

        foreach ($this->propertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedValue($normalizedState, $property);
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $normalizedState
     *
     * @return array<string, mixed>
     */
    public function optimisticColumnsFromNormalized(array $normalizedState): array
    {
        $columns = [];

        foreach ($this->optimisticPropertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedValue($normalizedState, $property);
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $normalizedState
     */
    private function requireNormalizedValue(array $normalizedState, string $property): mixed
    {
        if (!array_key_exists($property, $normalizedState)) {
            throw new \LogicException(sprintf(
                'State "%s" is missing normalized property "%s".',
                $this->stateClass,
                $property,
            ));
        }

        return $normalizedState[$property];
    }
}
