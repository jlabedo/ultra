<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\MappedTable\MappedKey;
use Ultra\MappedTable\MappedRow;

/**
 * @phpstan-type StateCollectionMetadataDefinition array{
 *     property: string,
 *     tableName: string,
 *     ownerScalar: bool,
 *     ownerPropertyColumns: array<string, string>,
 *     itemClass: class-string,
 *     itemIdProperty: string,
 *     itemIdScalar: bool,
 *     itemIdPropertyColumns: array<string, string>,
 *     itemPropertyColumns: array<string, string>,
 *     completeKey?: list<string>,
 *     readColumns?: list<string>
 * }
 */
final readonly class StateCollectionMetadata
{
    /** @var list<string> */
    private array $completeKey;

    /** @var list<string> */
    public array $readColumns;

    /**
     * @param class-string          $itemClass
     * @param array<string, string> $ownerPropertyColumns
     * @param array<string, string> $itemIdPropertyColumns
     * @param array<string, string> $itemPropertyColumns
     * @param list<string>|null $completeKey
     * @param list<string>|null $readColumns
     */
    public function __construct(
        public string $property,
        public string $tableName,
        public bool $ownerScalar,
        public array $ownerPropertyColumns,
        public string $itemClass,
        public string $itemIdProperty,
        public bool $itemIdScalar,
        public array $itemIdPropertyColumns,
        public array $itemPropertyColumns,
        ?array $completeKey = null,
        ?array $readColumns = null,
    ) {
        if (null === $completeKey) {
            $completeKey = [...array_values($ownerPropertyColumns), ...array_values($itemIdPropertyColumns)];
            sort($completeKey, SORT_STRING);
        }
        $this->completeKey = $completeKey;
        $this->readColumns =
            $readColumns ?? ([] === $itemPropertyColumns ? $completeKey : array_values($itemPropertyColumns));
    }

    /** @param StateCollectionMetadataDefinition $definition */
    public static function fromArray(array $definition): self
    {
        return new self(...$definition);
    }

    /** @return StateCollectionMetadataDefinition */
    public function toArray(): array
    {
        return [
            'property' => $this->property,
            'tableName' => $this->tableName,
            'ownerScalar' => $this->ownerScalar,
            'ownerPropertyColumns' => $this->ownerPropertyColumns,
            'itemClass' => $this->itemClass,
            'itemIdProperty' => $this->itemIdProperty,
            'itemIdScalar' => $this->itemIdScalar,
            'itemIdPropertyColumns' => $this->itemIdPropertyColumns,
            'itemPropertyColumns' => $this->itemPropertyColumns,
            'completeKey' => $this->completeKey,
            'readColumns' => $this->readColumns,
        ];
    }

    /** @return list<string> */
    public function keyColumns(): array
    {
        return $this->completeKey;
    }

    public function ownerKey(mixed $normalizedOwnerId): MappedKey
    {
        return $this->keyFromNormalized($normalizedOwnerId, $this->ownerScalar, $this->ownerPropertyColumns, 'owner');
    }

    /** @param array<array-key, mixed> $normalizedItem */
    public function itemKey(array $normalizedItem, MappedKey $ownerKey): MappedKey
    {
        if (!array_key_exists($this->itemIdProperty, $normalizedItem)) {
            throw new \LogicException(sprintf(
                'Collection "%s" item is missing id property "%s".',
                $this->property,
                $this->itemIdProperty,
            ));
        }

        return $ownerKey->mergedWith($this->keyFromNormalized(
            $normalizedItem[$this->itemIdProperty],
            $this->itemIdScalar,
            $this->itemIdPropertyColumns,
            'item',
        ));
    }

    /** @return array<string, mixed> */
    public function payloadFromRow(MappedRow $row): array
    {
        $payload = [
            $this->itemIdProperty => $this->normalizedFromKey(
                $row->key,
                $this->itemIdScalar,
                $this->itemIdPropertyColumns,
            ),
        ];
        foreach ($this->itemPropertyColumns as $property => $column) {
            $payload[$property] = array_key_exists($column, $row->columns) ? $row->columns[$column] : null;
        }

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $normalizedItem
     * @return array<string, mixed>
     */
    public function columnsFromNormalized(array $normalizedItem): array
    {
        $columns = [];
        foreach ($this->itemPropertyColumns as $property => $column) {
            if (!array_key_exists($property, $normalizedItem)) {
                throw new \LogicException(sprintf(
                    'Collection "%s" item is missing normalized property "%s".',
                    $this->property,
                    $property,
                ));
            }
            $columns[$column] = $normalizedItem[$property];
        }

        return $columns;
    }

    /** @param array<string, string> $mapping */
    private function keyFromNormalized(mixed $normalized, bool $scalar, array $mapping, string $role): MappedKey
    {
        if ($scalar) {
            if (!is_int($normalized) && !is_string($normalized)) {
                throw new \LogicException(sprintf(
                    'Collection "%s" %s id must normalize to int|string.',
                    $this->property,
                    $role,
                ));
            }

            return new MappedKey([array_values($mapping)[0] => $normalized]);
        }
        if (!is_array($normalized)) {
            throw new \LogicException(sprintf(
                'Collection "%s" %s id must normalize to an array.',
                $this->property,
                $role,
            ));
        }

        $expectedFields = array_keys($mapping);
        $actualFields = array_keys($normalized);
        sort($expectedFields, SORT_STRING);
        sort($actualFields, SORT_STRING);
        if ($expectedFields !== $actualFields) {
            throw new \LogicException(sprintf(
                'Collection "%s" %s id normalized fields do not match compiled fields.',
                $this->property,
                $role,
            ));
        }

        $values = [];
        foreach ($mapping as $property => $column) {
            $value = $normalized[$property] ?? null;
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException(sprintf(
                    'Collection "%s" %s id component "%s" must normalize to int|string.',
                    $this->property,
                    $role,
                    $property,
                ));
            }
            $values[$column] = $value;
        }

        return new MappedKey($values);
    }

    /** @param array<string, string> $mapping */
    private function normalizedFromKey(MappedKey $key, bool $scalar, array $mapping): int|string|array
    {
        if ($scalar) {
            return $key->value(array_values($mapping)[0]);
        }

        $normalized = [];
        foreach ($mapping as $property => $column) {
            $normalized[$property] = $key->value($column);
        }

        return $normalized;
    }
}
