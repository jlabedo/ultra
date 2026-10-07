<?php

declare(strict_types=1);

namespace Ultra\StateStore;

/**
 * @phpstan-type StateEmbeddedMetadataDefinition array{
 *     property: string,
 *     embeddedClass: class-string,
 *     propertyColumns: array<string, string>,
 *     optimisticPropertyColumns: array<string, string>
 * }
 */
final readonly class StateEmbeddedMetadata
{
    /**
     * @param class-string          $embeddedClass
     * @param array<string, string> $propertyColumns
     * @param array<string, string> $optimisticPropertyColumns
     */
    public function __construct(
        public string $property,
        public string $embeddedClass,
        public array $propertyColumns,
        public array $optimisticPropertyColumns,
    ) {}

    /**
     * @param StateEmbeddedMetadataDefinition $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            property: $definition['property'],
            embeddedClass: $definition['embeddedClass'],
            propertyColumns: $definition['propertyColumns'],
            optimisticPropertyColumns: $definition['optimisticPropertyColumns'],
        );
    }

    /**
     * @return StateEmbeddedMetadataDefinition
     */
    public function toArray(): array
    {
        return [
            'property' => $this->property,
            'embeddedClass' => $this->embeddedClass,
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
        $payload = [];

        foreach ($this->propertyColumns as $property => $column) {
            $payload[$property] = array_key_exists($column, $row) ? $row[$column] : null;
        }

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $normalizedState
     *
     * @return array<string, mixed>
     */
    public function columnsFromNormalized(array $normalizedState): array
    {
        $normalizedEmbedded = $this->requireNormalizedEmbedded($normalizedState);
        $columns = [];

        foreach ($this->propertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedProperty($normalizedEmbedded, $property);
        }

        return $columns;
    }

    /**
     * @param array<array-key, mixed> $normalizedState
     *
     * @return array<string, mixed>
     */
    public function optimisticColumnsFromNormalized(array $normalizedState): array
    {
        $normalizedEmbedded = $this->requireNormalizedEmbedded($normalizedState);
        $columns = [];

        foreach ($this->optimisticPropertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedProperty($normalizedEmbedded, $property);
        }

        return $columns;
    }

    /**
     * @param array<array-key, mixed> $normalizedState
     *
     * @return array<array-key, mixed>
     */
    private function requireNormalizedEmbedded(array $normalizedState): array
    {
        $embedded = $normalizedState[$this->property] ?? null;
        if (!is_array($embedded)) {
            throw new \LogicException(sprintf(
                'State embedded property "%s" must normalize to an array, got %s.',
                $this->property,
                get_debug_type($embedded),
            ));
        }

        return $embedded;
    }

    /**
     * @param array<array-key, mixed> $normalizedEmbedded
     */
    private function requireNormalizedProperty(array $normalizedEmbedded, string $property): mixed
    {
        if (!array_key_exists($property, $normalizedEmbedded)) {
            throw new \LogicException(sprintf(
                'State embedded property "%s" is missing normalized property "%s".',
                $this->property,
                $property,
            ));
        }

        return $normalizedEmbedded[$property];
    }
}
