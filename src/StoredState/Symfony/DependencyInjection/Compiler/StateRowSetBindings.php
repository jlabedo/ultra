<?php

declare(strict_types=1);

namespace Ultra\StateStore_Bundle\DependencyInjection\Compiler;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\Mapper\RuntimeNormalizerInterface;
use Ultra\Mapper_Compiler\CodeGeneratorBuilderRegistry;
use Ultra\Mapper_Compiler\CodeGeneratorContext;
use Ultra\StateStore\Exception\InvalidStateConfigurationException;
use Ultra\StateStore\StateMetadata;

/**
 * Validates object views against physical row set definitions during compilation.
 *
 * @phpstan-import-type Shape from \Ultra\Mapper_Compiler\NormalizationShapeAnalyzer
 * @internal
 */
final readonly class StateRowSetBindings
{
    /** @var array<string, class-string<MappedRowSetDefinition>> */
    private array $byTable;

    private CodeGeneratorBuilderRegistry $generators;

    private TypeResolver $typeResolver;

    /**
     * @param array<class-string<MappedRowSetDefinition>, MappedRowSetMapping> $mappings
     * @param array<class-string, Shape> $identities
     * @param array<class-string, class-string<RuntimeNormalizerInterface>> $runtimeNormalizers
     */
    public function __construct(
        private array $mappings,
        private array $identities,
        private array $runtimeNormalizers,
    ) {
        $byTable = [];
        foreach ($mappings as $definition => $mapping) {
            $byTable[$mapping->table] = $definition;
        }
        $this->byTable = $byTable;
        $this->generators = new CodeGeneratorBuilderRegistry(array_fill_keys(array_keys($runtimeNormalizers), true));
        $this->typeResolver = TypeResolver::create();
    }

    /**
     * @param iterable<StateMetadata> $states
     * @return array<string, class-string<MappedRowSetDefinition>>
     */
    public function compile(iterable $states): array
    {
        $bindings = [];
        foreach ($states as $state) {
            $context = sprintf('State "%s"', $state->stateClass);
            $definition = $this->bind($context, $state->tableName, $state->keyColumns());
            $bindings[$state->tableKey] = $definition;
            $mapping = $this->mappings[$definition];
            $this->assertIdentity($context, $state->stateClass, $state->idPropertyColumns, $mapping);
            $this->assertProperties($context, $state->stateClass, $state->propertyColumns, $mapping);

            foreach ($state->embedded as $embedded) {
                $this->storedProperty($state->stateClass, $embedded->property, $context);
                $this->assertProperties(
                    $context . ' embedded property "' . $embedded->property . '"',
                    $embedded->embeddedClass,
                    $embedded->propertyColumns,
                    $mapping,
                );
            }

            foreach ($state->collections as $collection) {
                $collectionContext = sprintf('State "%s" collection "%s"', $state->stateClass, $collection->property);
                $property = $this->storedProperty($state->stateClass, $collection->property, $collectionContext);
                $type = $this->typeResolver->resolve($property);
                $itemType = $type instanceof CollectionType ? $type->getCollectionValueType() : null;
                if (!$itemType instanceof ObjectType || $itemType->getClassName() !== $collection->itemClass) {
                    throw new InvalidStateConfigurationException(sprintf(
                        '%s must declare a collection of "%s" for Mapper.',
                        $collectionContext,
                        $collection->itemClass,
                    ));
                }

                $child = $this->bind($collectionContext, $collection->tableName, $collection->keyColumns());
                $bindings[$collection->tableKey] = $child;
                $childMapping = $this->mappings[$child];
                $owner = $childMapping->owner;
                $ownerColumns = [];
                foreach ($collection->ownerPropertyColumns as $field => $column) {
                    $ownerColumns[$column] = $state->idPropertyColumns[$field];
                }
                $declaredOwnerColumns = $owner->columns ?? [];
                ksort($ownerColumns, SORT_STRING);
                ksort($declaredOwnerColumns, SORT_STRING);
                if ($owner?->definition !== $definition || $declaredOwnerColumns !== $ownerColumns) {
                    throw new InvalidStateConfigurationException(sprintf(
                        '%s must match the owner dependency of mapped row set "%s".',
                        $collectionContext,
                        $child,
                    ));
                }
                $this->assertIdentity(
                    $collectionContext,
                    $collection->itemClass,
                    $collection->itemIdPropertyColumns,
                    $childMapping,
                );
                $this->assertProperties(
                    $collectionContext,
                    $collection->itemClass,
                    $collection->itemPropertyColumns,
                    $childMapping,
                );
            }
        }

        ksort($bindings, SORT_STRING);

        return $bindings;
    }

    /**
     * @param list<string> $keys
     * @return class-string<MappedRowSetDefinition>
     */
    private function bind(string $context, string $table, array $keys): string
    {
        $definition = $this->byTable[$table] ?? throw new InvalidStateConfigurationException(sprintf(
            '%s needs a mapped row set definition for table "%s".',
            $context,
            $table,
        ));
        if ($this->mappings[$definition]->keyColumns !== $keys) {
            throw new InvalidStateConfigurationException(sprintf(
                '%s must map the complete key of table "%s" [%s].',
                $context,
                $table,
                implode(', ', $this->mappings[$definition]->keyColumns),
            ));
        }

        return $definition;
    }

    /**
     * @param class-string $class
     * @param array<string, string> $columns
     */
    private function assertIdentity(string $context, string $class, array $columns, MappedRowSetMapping $mapping): void
    {
        $shape = $this->identities[$class];
        foreach ($columns as $property => $column) {
            $field = 'object' === $shape['kind'] ? $shape['fields'][$property] : $shape;
            assert('scalar' === $field['kind'], 'State metadata accepts only scalar identifier components.');
            $this->assertColumn($context, $property, $column, $field['types'], $mapping);
        }
    }

    /**
     * @param class-string $class
     * @param array<string, string> $columns
     */
    private function assertProperties(
        string $context,
        string $class,
        array $columns,
        MappedRowSetMapping $mapping,
    ): void {
        foreach ($columns as $property => $column) {
            $reflection = $this->storedProperty($class, $property, $context);
            $type = $reflection->getType();
            $normalizer = $type instanceof \ReflectionNamedType
                ? $this->runtimeNormalizers[$type->getName()] ?? null
                : null;
            $types = null !== $normalizer
                ? $this->runtimeTypes($normalizer, $type?->allowsNull() ?? false)
                : $this->columnTypes(
                    $this->generators
                        ->getCodeGenerator($reflection, CodeGeneratorContext::new()->with(isRoot: false))
                        ->getExpectedDenormalizedType(),
                );
            $this->assertColumn($context, $property, $column, $types, $mapping);
        }
    }

    /** @param class-string $class */
    private function storedProperty(string $class, string $property, string $context): \ReflectionProperty
    {
        $reflection = new \ReflectionProperty($class, $property);
        if (!$reflection->isPublic() || $reflection->isVirtual()) {
            throw new InvalidStateConfigurationException(sprintf(
                '%s property "%s" must be stored and public for Mapper.',
                $context,
                $property,
            ));
        }

        return $reflection;
    }

    /** @return list<string> */
    private function columnTypes(Type $type): array
    {
        $types = [];
        foreach ($type instanceof UnionType ? $type->getTypes() : [$type] as $member) {
            $types[] =
                array_find(['int', 'string', 'bool', 'float', 'array', 'null'], $member->isIdentifiedBy(...))
                ?? (string) $member;
        }

        return $types;
    }

    /**
     * @param class-string<RuntimeNormalizerInterface> $normalizer
     * @return list<string>
     */
    private function runtimeTypes(string $normalizer, bool $nullable): array
    {
        $return = new \ReflectionMethod($normalizer, 'toScalar')->getReturnType();
        $types = [];
        foreach ($return instanceof \ReflectionUnionType ? $return->getTypes() : [$return] as $type) {
            $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        }
        if ($nullable || ($return?->allowsNull() ?? false)) {
            $types[] = 'null';
        }

        return array_values(array_unique($types));
    }

    /** @param list<string> $types */
    private function assertColumn(
        string $context,
        string $property,
        string $column,
        array $types,
        MappedRowSetMapping $mapping,
    ): void {
        $declared = $mapping->columns[$column] ?? throw new InvalidStateConfigurationException(sprintf(
            '%s property "%s" maps unknown column "%s.%s".',
            $context,
            $property,
            $mapping->table,
            $column,
        ));
        $expected = $declared->nullable ? [$declared->type, 'null'] : [$declared->type];
        if ([] !== array_diff($types, $expected) || [] !== array_diff($expected, $types)) {
            throw new InvalidStateConfigurationException(sprintf(
                '%s property "%s" normalizes to %s, but mapped column "%s.%s" requires %s.',
                $context,
                $property,
                implode('|', $types),
                $mapping->table,
                $column,
                implode('|', $expected),
            ));
        }
    }
}
