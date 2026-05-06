<?php

declare(strict_types=1);

namespace Ultra\StateStore;

/**
 * @phpstan-type StateCollectionMetadataDefinition array{
 *     property: string,
 *     tableName: string,
 *     tableKey: string,
 *     ownerColumn: string,
 *     idColumn: string,
 *     itemClass: class-string,
 *     itemIdProperty: string,
 *     itemPropertyColumns: array<string, string>
 * }
 */
final readonly class StateCollectionMetadata
{
    /**
     * @param class-string $itemClass
     * @param array<string, string> $itemPropertyColumns
     */
    public function __construct(
        public string $property,
        public string $tableName,
        public string $tableKey,
        public string $ownerColumn,
        public string $idColumn,
        public string $itemClass,
        public string $itemIdProperty,
        public array $itemPropertyColumns,
    ) {}

    /**
     * @param StateCollectionMetadataDefinition $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            property: $definition['property'],
            tableName: $definition['tableName'],
            tableKey: $definition['tableKey'],
            ownerColumn: $definition['ownerColumn'],
            idColumn: $definition['idColumn'],
            itemClass: $definition['itemClass'],
            itemIdProperty: $definition['itemIdProperty'],
            itemPropertyColumns: $definition['itemPropertyColumns'],
        );
    }

    /**
     * @return StateCollectionMetadataDefinition
     */
    public function toArray(): array
    {
        return [
            'property' => $this->property,
            'tableName' => $this->tableName,
            'tableKey' => $this->tableKey,
            'ownerColumn' => $this->ownerColumn,
            'idColumn' => $this->idColumn,
            'itemClass' => $this->itemClass,
            'itemIdProperty' => $this->itemIdProperty,
            'itemPropertyColumns' => $this->itemPropertyColumns,
        ];
    }

    /**
     * @return list<string>
     */
    public function selectedColumns(): array
    {
        return array_values(array_unique(array_values($this->itemPropertyColumns)));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function payloadFromRow(int|string $id, array $row): array
    {
        $payload = [
            $this->itemIdProperty => $id,
        ];

        foreach ($this->itemPropertyColumns as $property => $column) {
            $payload[$property] = array_key_exists($column, $row) ? $row[$column] : null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $normalizedItem
     */
    public function identifierFromNormalized(array $normalizedItem): int|string
    {
        $id = $this->requireNormalizedValue($normalizedItem, $this->itemIdProperty);

        if (!is_string($id) && !is_int($id)) {
            throw new \LogicException(sprintf(
                'State collection "%s" normalized item id "%s" must be a string or int.',
                $this->property,
                $this->itemIdProperty,
            ));
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $normalizedItem
     *
     * @return array<string, mixed>
     */
    public function columnsFromNormalized(array $normalizedItem): array
    {
        $columns = [];

        foreach ($this->itemPropertyColumns as $property => $column) {
            $columns[$column] = $this->requireNormalizedValue($normalizedItem, $property);
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $normalizedItem
     */
    private function requireNormalizedValue(array $normalizedItem, string $property): mixed
    {
        if (!array_key_exists($property, $normalizedItem)) {
            throw new \LogicException(sprintf(
                'State collection "%s" item is missing normalized property "%s".',
                $this->property,
                $property,
            ));
        }

        return $normalizedItem[$property];
    }
}
