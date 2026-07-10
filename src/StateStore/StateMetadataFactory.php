<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
use Ultra\StateStore\Attribute\StateCollection;
use Ultra\StateStore\Attribute\StateEmbedded;
use Ultra\StateStore\Attribute\StateId;
use Ultra\StateStore\Exception\InvalidStateConfigurationException;

final class StateMetadataFactory
{
    /**
     * @param class-string $stateClass
     */
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

        if ([] === $attributes) {
            throw new InvalidStateConfigurationException(sprintf(
                'Cannot map state "%s" because it is missing the #[%s] attribute.',
                $stateClass,
                State::class,
            ));
        }

        if (1 !== \count($attributes)) {
            throw new InvalidStateConfigurationException(sprintf(
                'Cannot map state "%s" because it declares #[%s] more than once.',
                $stateClass,
                State::class,
            ));
        }

        $state = $attributes[0]->newInstance();
        $properties = array_values(array_filter(
            $reflection->getProperties(),
            static fn (\ReflectionProperty $property): bool => !$property->isStatic(),
        ));
        usort($properties, static fn (\ReflectionProperty $left, \ReflectionProperty $right): int => $left->getName() <=> $right->getName());

        $idProperty = null;
        $propertyColumns = [];
        $optimisticPropertyColumns = [];
        $embedded = [];
        $collections = [];
        $mappedColumns = [];

        foreach ($properties as $property) {
            $idAttributes = $property->getAttributes(StateId::class);
            $columnAttributes = $property->getAttributes(StateColumn::class);
            $collectionAttributes = $property->getAttributes(StateCollection::class);
            $embeddedAttributes = $property->getAttributes(StateEmbedded::class);

            if (\count($idAttributes) > 1) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" declares #[%s] more than once.',
                    $stateClass,
                    $property->getName(),
                    StateId::class,
                ));
            }

            if (\count($columnAttributes) > 1) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" declares #[%s] more than once.',
                    $stateClass,
                    $property->getName(),
                    StateColumn::class,
                ));
            }

            if (\count($collectionAttributes) > 1) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" declares #[%s] more than once.',
                    $stateClass,
                    $property->getName(),
                    StateCollection::class,
                ));
            }

            if (\count($embeddedAttributes) > 1) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" declares #[%s] more than once.',
                    $stateClass,
                    $property->getName(),
                    StateEmbedded::class,
                ));
            }

            if (1 < \count(array_filter([$idAttributes, $columnAttributes, $collectionAttributes, $embeddedAttributes]))) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" cannot declare more than one state mapping attribute.',
                    $stateClass,
                    $property->getName(),
                ));
            }

            if ([] !== $idAttributes) {
                if (null !== $idProperty) {
                    throw new InvalidStateConfigurationException(sprintf(
                        'State "%s" must declare exactly one #[%s] property.',
                        $stateClass,
                        StateId::class,
                    ));
                }

                $idProperty = $property->getName();

                continue;
            }

            if ([] !== $collectionAttributes) {
                $collection = $collectionAttributes[0]->newInstance();
                $collections[$property->getName()] = $this->createCollectionMetadata($property, $collection);

                continue;
            }

            if ([] !== $embeddedAttributes) {
                $embeddedMetadata = $this->createEmbeddedMetadata($stateClass, $property, $embeddedAttributes[0]->newInstance());
                foreach ($embeddedMetadata->propertyColumns as $embeddedProperty => $columnName) {
                    $this->assertColumnAvailable($state, $stateClass, $property->getName().'.'.$embeddedProperty, $columnName, $mappedColumns);
                }
                $embedded[$property->getName()] = $embeddedMetadata;

                continue;
            }

            $column = [] === $columnAttributes ? new StateColumn() : $columnAttributes[0]->newInstance();
            $columnName = $column->name ?? $property->getName();

            $this->assertColumnAvailable($state, $stateClass, $property->getName(), $columnName, $mappedColumns);

            $propertyColumns[$property->getName()] = $columnName;

            if ($column->optimistic) {
                $optimisticPropertyColumns[$property->getName()] = $columnName;
            }
        }

        if (null === $idProperty) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" must declare exactly one #[%s] property.',
                $stateClass,
                StateId::class,
            ));
        }

        return new StateMetadata(
            stateClass: $stateClass,
            tableName: $state->table,
            tableKey: self::tableKey($state->table, $state->idColumn),
            idProperty: $idProperty,
            idColumn: $state->idColumn,
            propertyColumns: $propertyColumns,
            optimisticPropertyColumns: $optimisticPropertyColumns,
            embedded: $embedded,
            collections: $collections,
        );
    }

    public static function tableKey(string $tableName, string $idColumn): string
    {
        return $tableName.'#'.$idColumn;
    }

    private function createCollectionMetadata(\ReflectionProperty $property, StateCollection $collection): StateCollectionMetadata
    {
        $itemMetadata = $this->create($collection->itemClass);

        if ([] !== $itemMetadata->collections) {
            throw new InvalidStateConfigurationException(sprintf(
                'State collection "%s" item class "%s" cannot declare nested state collections.',
                $property->getName(),
                $collection->itemClass,
            ));
        }

        if (
            $itemMetadata->idColumn === $collection->ownerColumn
            || in_array($collection->ownerColumn, $itemMetadata->propertyColumns, true)
        ) {
            throw new InvalidStateConfigurationException(sprintf(
                'State collection "%s" item class "%s" cannot map to owner column "%s".',
                $property->getName(),
                $collection->itemClass,
                $collection->ownerColumn,
            ));
        }

        return new StateCollectionMetadata(
            property: $property->getName(),
            tableName: $itemMetadata->tableName,
            tableKey: $itemMetadata->tableKey,
            ownerColumn: $collection->ownerColumn,
            idColumn: $itemMetadata->idColumn,
            itemClass: $itemMetadata->stateClass,
            itemIdProperty: $itemMetadata->idProperty,
            itemPropertyColumns: $itemMetadata->propertyColumns,
        );
    }

    /**
     * @param array<string, string> $mappedColumns
     */
    private function assertColumnAvailable(State $state, string $stateClass, string $property, string $columnName, array &$mappedColumns): void
    {
        if ($state->idColumn === $columnName) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" property "%s" cannot map to id column "%s".',
                $stateClass,
                $property,
                $state->idColumn,
            ));
        }

        if (isset($mappedColumns[$columnName])) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" maps column "%s" more than once (%s and %s).',
                $stateClass,
                $columnName,
                $mappedColumns[$columnName],
                $property,
            ));
        }

        $mappedColumns[$columnName] = $property;
    }

    private function createEmbeddedMetadata(string $stateClass, \ReflectionProperty $property, StateEmbedded $embedded): StateEmbeddedMetadata
    {
        if ('' === $embedded->prefix) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" embedded property "%s" must declare a non-empty column prefix.',
                $stateClass,
                $property->getName(),
            ));
        }

        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" embedded property "%s" must have a non-null object type.',
                $stateClass,
                $property->getName(),
            ));
        }

        $embeddedClass = $type->getName();
        $reflection = new \ReflectionClass($embeddedClass);
        if ([] !== $reflection->getAttributes(State::class)) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" embedded property "%s" cannot embed state class "%s".',
                $stateClass,
                $property->getName(),
                $embeddedClass,
            ));
        }

        $properties = array_values(array_filter(
            $reflection->getProperties(),
            static fn (\ReflectionProperty $embeddedProperty): bool => !$embeddedProperty->isStatic(),
        ));
        usort($properties, static fn (\ReflectionProperty $left, \ReflectionProperty $right): int => $left->getName() <=> $right->getName());

        if ([] === $properties) {
            throw new InvalidStateConfigurationException(sprintf(
                'State "%s" embedded property "%s" has no mapped properties.',
                $stateClass,
                $property->getName(),
            ));
        }

        $propertyColumns = [];
        $optimisticPropertyColumns = [];
        foreach ($properties as $embeddedProperty) {
            if ([] !== $embeddedProperty->getAttributes(StateId::class)
                || [] !== $embeddedProperty->getAttributes(StateCollection::class)
                || [] !== $embeddedProperty->getAttributes(StateEmbedded::class)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" embedded property "%s.%s" cannot declare state identity, collection, or embedded mapping attributes.',
                    $stateClass,
                    $property->getName(),
                    $embeddedProperty->getName(),
                ));
            }

            $columnAttributes = $embeddedProperty->getAttributes(StateColumn::class);
            if (1 < \count($columnAttributes)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" embedded property "%s.%s" declares #[%s] more than once.',
                    $stateClass,
                    $property->getName(),
                    $embeddedProperty->getName(),
                    StateColumn::class,
                ));
            }

            $column = [] === $columnAttributes ? new StateColumn() : $columnAttributes[0]->newInstance();
            $columnName = $embedded->prefix.($column->name ?? $embeddedProperty->getName());
            if (in_array($columnName, $propertyColumns, true)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" embedded property "%s" maps column "%s" more than once.',
                    $stateClass,
                    $property->getName(),
                    $columnName,
                ));
            }
            $propertyColumns[$embeddedProperty->getName()] = $columnName;

            if ($embedded->optimistic && $column->optimistic) {
                $optimisticPropertyColumns[$embeddedProperty->getName()] = $columnName;
            }
        }

        return new StateEmbeddedMetadata(
            property: $property->getName(),
            embeddedClass: $embeddedClass,
            propertyColumns: $propertyColumns,
            optimisticPropertyColumns: $optimisticPropertyColumns,
        );
    }
}
