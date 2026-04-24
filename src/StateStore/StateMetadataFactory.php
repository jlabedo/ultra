<?php

declare(strict_types=1);

namespace Ultra\StateStore;

use Ultra\StateStore\Attribute\State;
use Ultra\StateStore\Attribute\StateColumn;
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

        foreach ($properties as $property) {
            $idAttributes = $property->getAttributes(StateId::class);
            $columnAttributes = $property->getAttributes(StateColumn::class);

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

            if ([] !== $idAttributes && [] !== $columnAttributes) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" cannot be both the state id and a mapped column.',
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

            $column = [] === $columnAttributes ? new StateColumn() : $columnAttributes[0]->newInstance();
            $columnName = $column->name ?? $property->getName();

            if ($state->idColumn === $columnName) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" property "%s" cannot map to id column "%s".',
                    $stateClass,
                    $property->getName(),
                    $state->idColumn,
                ));
            }

            if (in_array($columnName, $propertyColumns, true)) {
                throw new InvalidStateConfigurationException(sprintf(
                    'State "%s" maps column "%s" more than once.',
                    $stateClass,
                    $columnName,
                ));
            }

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
        );
    }

    public static function tableKey(string $tableName, string $idColumn): string
    {
        return $tableName.'#'.$idColumn;
    }
}
