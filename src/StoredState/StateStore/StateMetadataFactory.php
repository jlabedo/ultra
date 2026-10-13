<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\InvalidStateConfigurationException;

/**
 * Builds runtime metadata from normalization shapes already resolved by Mapper Compiler.
 */
final readonly class StateMetadataFactory
{
    /**
     * @param array<class-string, array<string, mixed>> $identityShapes
     */
    public function __construct(
        private array $identityShapes,
    ) {}

    /** @param class-string $stateClass */
    public function create(string $stateClass): StateMetadata
    {
        if (!class_exists($stateClass)) {
            throw new InvalidStateConfigurationException(sprintf(
                'Cannot map state "%s" because the class does not exist.',
                $stateClass,
            ));
        }

        $reflection = new \ReflectionClass($stateClass);
        $attributes = $reflection->getAttributes(State::class);
        if (1 !== count($attributes)) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" must declare exactly one #[%s].',
                $stateClass,
                State::class,
            ));
        }
        $state = $attributes[0]->newInstance();
        $properties = $this->instanceProperties($reflection);

        $idProperties = array_values(array_filter(
            $properties,
            static fn(\ReflectionProperty $property): bool => [] !== $property->getAttributes(StateId::class),
        ));
        if (1 !== count($idProperties)) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" must declare exactly one #[%s] property.',
                $stateClass,
                StateId::class,
            ));
        }

        $idProperty = $idProperties[0];
        $idType = $this->requireIdType($stateClass, $idProperty);
        $shape = $this->identityShapes[$stateClass] ?? null;
        if (!is_array($shape)) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" has no compiled Mapper identity shape.',
                $stateClass,
            ));
        }
        [$idScalar, $idPropertyColumns] = $this->idMapping($stateClass, $idProperty, $shape);

        $propertyColumns = [];
        $optimisticPropertyColumns = [];
        $embedded = [];
        $collections = [];
        $mappedColumns = array_fill_keys(array_values($idPropertyColumns), $idProperty->getName());

        foreach ($properties as $property) {
            if ($property === $idProperty) {
                continue;
            }

            $columnAttributes = $property->getAttributes(StateColumn::class);
            $collectionAttributes = $property->getAttributes(StateCollection::class);
            $embeddedAttributes = $property->getAttributes(StateEmbedded::class);
            if (1 < count(array_filter([$columnAttributes, $collectionAttributes, $embeddedAttributes]))) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" cannot declare more than one state mapping attribute.',
                    $stateClass,
                    $property->getName(),
                ));
            }

            if ([] !== $collectionAttributes) {
                $collection = $collectionAttributes[0]->newInstance();
                $collections[$property->getName()] = $this->createCollectionMetadata(
                    $property,
                    $collection,
                    $idScalar,
                    $idPropertyColumns,
                );
                continue;
            }

            if ([] !== $embeddedAttributes) {
                $embeddedMetadata = $this->createEmbeddedMetadata(
                    $stateClass,
                    $property,
                    $embeddedAttributes[0]->newInstance(),
                );
                foreach ($embeddedMetadata->propertyColumns as $embeddedProperty => $columnName) {
                    $this->assertColumnAvailable(
                        $stateClass,
                        $property->getName() . '.' . $embeddedProperty,
                        $columnName,
                        $mappedColumns,
                    );
                }
                $embedded[$property->getName()] = $embeddedMetadata;
                continue;
            }

            $column = [] === $columnAttributes ? new StateColumn() : $columnAttributes[0]->newInstance();
            $columnName = $column->name ?? self::columnName($property->getName());
            $this->assertColumnAvailable($stateClass, $property->getName(), $columnName, $mappedColumns);
            $propertyColumns[$property->getName()] = $columnName;
            if ($column->optimistic) {
                $optimisticPropertyColumns[$property->getName()] = $columnName;
            }
        }

        return new StateMetadata(
            stateClass: $stateClass,
            tableName: $state->table,
            tableKey: self::tableKey($state->table, array_values($idPropertyColumns)),
            idProperty: $idProperty->getName(),
            idType: $idType,
            idScalar: $idScalar,
            idPropertyColumns: $idPropertyColumns,
            propertyColumns: $propertyColumns,
            optimisticPropertyColumns: $optimisticPropertyColumns,
            embedded: $embedded,
            collections: $collections,
        );
    }

    /** @param list<string> $keyColumns */
    public static function tableKey(string $tableName, array $keyColumns): string
    {
        sort($keyColumns, SORT_STRING);

        return $tableName . '#' . implode(',', $keyColumns);
    }

    /**
     * @param array<string, mixed>  $shape
     *
     * @return array{bool, array<string, string>}
     */
    private function idMapping(string $stateClass, \ReflectionProperty $property, array $shape): array
    {
        $attribute = $property->getAttributes(StateId::class)[0]->newInstance();
        $kind = $shape['kind'] ?? null;
        if ('scalar' === $kind) {
            $this->assertScalarShape($stateClass, $property->getName(), $shape);
            if ([] !== $attribute->columns) {
                throw new InvalidStateConfigurationException(sprintf(
                    'Scalar state id %s::$%s cannot declare columns.',
                    $stateClass,
                    $property->getName(),
                ));
            }

            $column = $attribute->column ?? self::columnName($property->getName());
            if ('' === $column) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State id %s::$%s maps an empty column name.',
                    $stateClass,
                    $property->getName(),
                ));
            }

            return [true, [$property->getName() => $column]];
        }
        if ('object' !== $kind || !is_array($shape['fields'] ?? null) || [] === $shape['fields']) {
            throw new InvalidStateConfigurationException(sprintf(
                'State id %s::$%s has an unsupported Mapper shape.',
                $stateClass,
                $property->getName(),
            ));
        }
        if (null !== $attribute->column) {
            throw new InvalidStateConfigurationException(sprintf(
                'Composite state id %s::$%s cannot declare column.',
                $stateClass,
                $property->getName(),
            ));
        }

        $mapping = [];
        foreach ($shape['fields'] as $field => $fieldShape) {
            if (!is_string($field) || !is_array($fieldShape)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State id %s::$%s has an invalid Mapper field shape.',
                    $stateClass,
                    $property->getName(),
                ));
            }
            $this->assertScalarShape($stateClass, $property->getName() . '.' . $field, $fieldShape);
            $mapping[$field] = $attribute->columns[$field] ?? self::columnName($field);
            if ('' === $mapping[$field]) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State id %s::$%s component "%s" maps an empty column name.',
                    $stateClass,
                    $property->getName(),
                    $field,
                ));
            }
        }
        foreach ($attribute->columns as $field => $_) {
            if (!array_key_exists($field, $mapping)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State id %s::$%s maps unknown component "%s".',
                    $stateClass,
                    $property->getName(),
                    $field,
                ));
            }
        }
        if (count($mapping) !== count(array_unique($mapping))) {
            throw new InvalidStateConfigurationException(sprintf(
                'State id %s::$%s maps multiple components to the same column.',
                $stateClass,
                $property->getName(),
            ));
        }

        return [false, $mapping];
    }

    /** @param array<array-key, mixed> $shape */
    private function assertScalarShape(string $stateClass, string $property, array $shape): void
    {
        $types = $shape['types'] ?? null;
        if (
            'scalar' !== ($shape['kind'] ?? null)
            || !is_array($types)
            || [] === $types
            || [] !== array_filter($types, static fn(mixed $type): bool => !in_array($type, ['int', 'string'], true))
        ) {
            throw new InvalidStateConfigurationException(sprintf(
                'State id component %s::$%s must normalize to int|string.',
                $stateClass,
                $property,
            ));
        }
    }

    /**
     * @param array<string, string> $ownerPropertyColumns
     */
    private function createCollectionMetadata(
        \ReflectionProperty $property,
        StateCollection $collection,
        bool $ownerScalar,
        array $ownerPropertyColumns,
    ): StateCollectionMetadata {
        $itemMetadata = $this->create($collection->itemClass);
        if ([] !== $itemMetadata->collections || [] !== $itemMetadata->embedded) {
            throw new InvalidStateConfigurationException(sprintf(
                'State collection "%s" item class "%s" cannot declare collections or embedded properties.',
                $property->getName(),
                $collection->itemClass,
            ));
        }

        foreach ($collection->ownerColumns as $component => $_) {
            if (!array_key_exists($component, $ownerPropertyColumns)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State collection "%s" maps unknown owner id component "%s".',
                    $property->getName(),
                    $component,
                ));
            }
        }
        $resolvedOwnerColumns = [];
        foreach ($ownerPropertyColumns as $component => $column) {
            $resolvedOwnerColumns[$component] = $collection->ownerColumns[$component] ?? $column;
            if ('' === $resolvedOwnerColumns[$component]) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State collection "%s" owner component "%s" maps an empty column name.',
                    $property->getName(),
                    $component,
                ));
            }
        }

        $keyColumns = [...array_values($resolvedOwnerColumns), ...$itemMetadata->keyColumns()];
        if (count($keyColumns) !== count(array_unique($keyColumns))) {
            throw new InvalidStateConfigurationException(sprintf(
                'State collection "%s" owner and item keys map overlapping columns.',
                $property->getName(),
            ));
        }
        if ([] !== array_intersect(array_values($resolvedOwnerColumns), $itemMetadata->selectedColumns())) {
            throw new InvalidStateConfigurationException(sprintf(
                'State collection "%s" owner key overlaps an item data column.',
                $property->getName(),
            ));
        }

        return new StateCollectionMetadata(
            property: $property->getName(),
            tableName: $itemMetadata->tableName,
            tableKey: self::tableKey($itemMetadata->tableName, $keyColumns),
            ownerScalar: $ownerScalar,
            ownerPropertyColumns: $resolvedOwnerColumns,
            itemClass: $itemMetadata->stateClass,
            itemIdProperty: $itemMetadata->idProperty,
            itemIdScalar: $itemMetadata->idScalar,
            itemIdPropertyColumns: $itemMetadata->idPropertyColumns,
            itemPropertyColumns: $itemMetadata->propertyColumns,
        );
    }

    /** @param array<string, string> $mappedColumns */
    private function assertColumnAvailable(
        string $stateClass,
        string $property,
        string $columnName,
        array &$mappedColumns,
    ): void {
        if (isset($mappedColumns[$columnName])) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" properties "%s" and "%s" both map column "%s".',
                $stateClass,
                $mappedColumns[$columnName],
                $property,
                $columnName,
            ));
        }
        $mappedColumns[$columnName] = $property;
    }

    /**
     * @return 'int'|'string'|class-string
     */
    private function requireIdType(string $stateClass, \ReflectionProperty $property): string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->allowsNull()) {
            throw new InvalidStateConfigurationException(sprintf(
                'State id %s::$%s must have one non-null named type.',
                $stateClass,
                $property->getName(),
            ));
        }
        $idType = $type->getName();
        if ($type->isBuiltin() && !in_array($idType, ['int', 'string'], true)) {
            throw new InvalidStateConfigurationException(sprintf(
                'State id %s::$%s must be int, string, or an object.',
                $stateClass,
                $property->getName(),
            ));
        }

        /** @var 'int'|'string'|class-string $idType */
        return $idType;
    }

    /** @return list<\ReflectionProperty> */
    private function instanceProperties(\ReflectionClass $reflection): array
    {
        $properties = array_values(array_filter(
            $reflection->getProperties(),
            static fn(\ReflectionProperty $property): bool => !$property->isStatic(),
        ));
        usort(
            $properties,
            static fn(
                \ReflectionProperty $left,
                \ReflectionProperty $right,
            ): int => $left->getName() <=> $right->getName(),
        );

        return $properties;
    }

    private function createEmbeddedMetadata(
        string $stateClass,
        \ReflectionProperty $property,
        StateEmbedded $embedded,
    ): StateEmbeddedMetadata {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" embedded property "%s" must have one non-null object type.',
                $stateClass,
                $property->getName(),
            ));
        }

        /** @var class-string $embeddedClass */
        $embeddedClass = $type->getName();
        $reflection = new \ReflectionClass($embeddedClass);
        $propertyColumns = [];
        $optimisticPropertyColumns = [];
        foreach ($this->instanceProperties($reflection) as $embeddedProperty) {
            if (
                [] !== $embeddedProperty->getAttributes(StateId::class)
                || [] !== $embeddedProperty->getAttributes(StateCollection::class)
                || [] !== $embeddedProperty->getAttributes(StateEmbedded::class)
            ) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" embedded property "%s" cannot contain state mappings.',
                    $stateClass,
                    $property->getName(),
                ));
            }
            $columnAttributes = $embeddedProperty->getAttributes(StateColumn::class);
            $column = [] === $columnAttributes ? new StateColumn() : $columnAttributes[0]->newInstance();
            $columnName = $embedded->prefix . ($column->name ?? self::columnName($embeddedProperty->getName()));
            $propertyColumns[$embeddedProperty->getName()] = $columnName;
            if ($embedded->optimistic && $column->optimistic) {
                $optimisticPropertyColumns[$embeddedProperty->getName()] = $columnName;
            }
        }

        return new StateEmbeddedMetadata(
            $property->getName(),
            $embeddedClass,
            $propertyColumns,
            $optimisticPropertyColumns,
        );
    }

    private static function columnName(string $property): string
    {
        $words = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $property);
        assert(null !== $words, 'The column naming expression is valid.');
        $words = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $words);
        assert(null !== $words, 'The column naming expression is valid.');

        return strtolower($words);
    }
}
