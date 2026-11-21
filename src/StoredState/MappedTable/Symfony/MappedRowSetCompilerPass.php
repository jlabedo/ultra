<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\InMemoryMappedRowSetAdapterFactory;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableDependencies;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;
use Ultra\MappedTable\PostgresMappedRowSetSql;
use Ultra\UnitOfWork\TagNames;

/** @internal */
final class MappedRowSetCompilerPass implements CompilerPassInterface
{
    public const string DEFINITION_TAG = 'ultra.mapped_table.definition';

    public const string MAPPINGS = 'ultra.mapped_table.compiling.mappings';

    public function process(ContainerBuilder $container): void
    {
        $resources = $container->findTaggedResourceIds(self::DEFINITION_TAG);
        if ([] === $resources) {
            $container->setParameter(self::MAPPINGS, []);

            return;
        }

        $factory = $container->findDefinition(MappedRowSetAdapterFactory::class);
        $factoryClass = $factory->getClass();
        $mappings = [];
        $rowSets = [];
        foreach ($resources as $id => $tags) {
            $class = $tags[0]['class'] ?? $container->findDefinition($id)->getClass();
            if (!is_string($class) || !is_a($class, MappedRowSetDefinition::class, true)) {
                throw new \LogicException(sprintf(
                    'Mapped row set resource "%s" must implement MappedRowSetDefinition.',
                    $id,
                ));
            }
            if (isset($mappings[$class])) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || [] !== ($reflection->getConstructor()?->getParameters() ?? [])) {
                throw new \LogicException(sprintf(
                    'Mapped row set definition "%s" must be instantiable without constructor parameters.',
                    $class,
                ));
            }

            $definition = $reflection->newInstance();
            $mapping = $definition->mapping();
            $mappings[$class] = $mapping;
            $rowSets[$class] = new Reference($class);

            $metadata = new Definition(MappedRowSetMetadata::class, [
                $mapping->table,
                array_map(
                    static fn(MappedColumn $column): Definition => new Definition(MappedColumn::class, [
                        $column->type,
                        $column->nullable,
                    ]),
                    $mapping->columns,
                ),
                $mapping->keyColumns,
                $mapping->ownerColumns,
                null === $mapping->owner
                    ? null
                    : new Definition(MappedRowSetOwner::class, [$mapping->owner->definition, $mapping->owner->columns]),
                $mapping->columnNames,
                $mapping->nonKeyColumns,
                $mapping->keyColumnSet,
                $mapping->ownerColumnSet,
            ]);
            $metadataId = 'ultra.mapped_table.metadata.' . $class;
            $container->setDefinition($metadataId, $metadata);
            $metadataReference = new Reference($metadataId);
            $adapter = match ($factoryClass) {
                PostgresMappedRowSetAdapterFactory::class => $this->postgresAdapter(
                    $container,
                    $factory,
                    $mapping,
                    $definition,
                    $metadataReference,
                ),
                InMemoryMappedRowSetAdapterFactory::class => new Definition(MappedRowSetAdapter::class)->setFactory([
                    new Reference(MappedRowSetAdapterFactory::class),
                    'createDefined',
                ])->setArguments([$metadataReference]),
                default => new Definition(MappedRowSetAdapter::class)->setFactory([
                    new Reference(MappedRowSetAdapterFactory::class),
                    'create',
                ])->setArguments([$mapping->table, $mapping->keyColumns]),
            };
            $container->setDefinition(
                $class,
                new Definition(MappedRowSet::class, [$adapter, $metadataReference, true]),
            );
        }

        $schedule = MappedTableDependencies::compile($mappings);
        $container->setParameter(self::MAPPINGS, $mappings);
        $container
            ->register(MappedTableUnitOfWork::class, MappedTableUnitOfWork::class)
            ->setArguments([$rowSets, $schedule])
            ->addTag(TagNames::UNIT_OF_WORK);
    }

    private function postgresAdapter(
        ContainerBuilder $container,
        Definition $factory,
        MappedRowSetMapping $mapping,
        MappedRowSetDefinition $definition,
        Reference $metadata,
    ): Definition {
        $configuredTypes = $definition instanceof PostgresMappedRowSetConfiguration
            ? $definition->postgresColumnTypes()
            : [];
        [$factoryTypes, $needsRuntimeDefaults] = $this->postgresDefaults(
            $container,
            $factory,
            $mapping,
            $configuredTypes,
        );
        $configuredTypes += $factoryTypes;
        $types = [];
        foreach ($configuredTypes as $column => $type) {
            $type->assertSupports($column, $mapping->column($column));
            $types[$column] = new Definition(PostgresMappedColumnType::class)->setFactory([
                PostgresMappedColumnType::class,
                $type->factoryMethod,
            ]);
        }

        $sql = PostgresMappedRowSetSql::compile($mapping, $configuredTypes);
        $compiledSql = new Definition(PostgresMappedRowSetSql::class, [
            $sql->table,
            $sql->columns,
            $sql->keyPredicates,
            $sql->keyPredicate,
            $sql->keyList,
            $sql->ownerColumns,
            $sql->ownerPredicates,
            $sql->ownerWhere,
            $sql->columnNames,
            $sql->nonKeyColumns,
            $sql->rowColumns,
            $sql->rowSetColumns,
            $sql->absentRowLockNamespace,
        ]);

        return new Definition(MappedRowSetAdapter::class)->setFactory([
            new Reference(MappedRowSetAdapterFactory::class),
            'createDefined',
        ])->setArguments([$metadata, $types, $compiledSql, $needsRuntimeDefaults]);
    }

    /**
     * @param array<string, PostgresMappedColumnType> $declared
     * @return array{array<string, PostgresMappedColumnType>, bool} Known defaults and whether other defaults need the runtime factory.
     */
    private function postgresDefaults(
        ContainerBuilder $container,
        Definition $factory,
        MappedRowSetMapping $mapping,
        array $declared,
    ): array {
        if ($factory->isSynthetic() || null !== $factory->getFactory()) {
            return [[], true];
        }

        $arguments = $factory->getArguments();
        $argument = $arguments['$columnTypesByTable'] ?? $arguments[1] ?? null;
        if (null === $argument) {
            $bindings = $factory->getBindings();
            $binding =
                $bindings['array $columnTypesByTable']
                ?? $bindings['$columnTypesByTable']
                ?? $bindings['array']
                ?? null;
            $argument = $binding?->getValues()[0] ?? [];
        }

        $tables = $container->getParameterBag()->resolveValue($argument);
        if (!is_array($tables)) {
            return [[], true];
        }

        $defaults = $tables[$mapping->table] ?? [];
        if (!is_array($defaults)) {
            return [[], true];
        }

        $types = [];
        $needsRuntimeDefaults = false;
        foreach (array_diff_key(array_intersect_key($defaults, $mapping->columns), $declared) as $column => $service) {
            if (!is_string($column)) {
                continue;
            }
            if ($service instanceof Reference) {
                $service = $container->findDefinition((string) $service);
            }
            $codecFactory =
                $service instanceof Definition && [] === $service->getArguments() ? $service->getFactory() : null;
            if (
                !is_array($codecFactory)
                || PostgresMappedColumnType::class !== ($codecFactory[0] ?? null)
                || !is_string($codecFactory[1] ?? null)
                || null === ($type = PostgresMappedColumnType::fromFactoryMethod($codecFactory[1]))
            ) {
                $needsRuntimeDefaults = true;

                continue;
            }

            $types[$column] = $type;
        }

        return [$types, $needsRuntimeDefaults];
    }
}
