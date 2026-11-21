<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTableDependencies;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;
use Ultra\MappedTable\PostgresMappedRowSetSql;
use Ultra\MappedTable\PostgresMappedTableAdapter;
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

        $adapter = $container->findDefinition(MappedTableAdapter::class);
        if (!$adapter->isShared()) {
            throw new \LogicException('Compiled mapped row sets require a shared MappedTableAdapter.');
        }
        $adapterClass = $container->getParameterBag()->resolveValue($adapter->getClass());
        if (!is_string($adapterClass) || !is_a($adapterClass, MappedTableAdapter::class, true)) {
            throw new \LogicException('The mapped table adapter service must extend MappedTableAdapter.');
        }
        if (new \ReflectionClass($adapterClass)->isAbstract()) {
            throw new \LogicException('The mapped table adapter service must declare its concrete class.');
        }
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
            $rowSet = match ($adapterClass) {
                PostgresMappedTableAdapter::class => $this->postgresRowSet($mapping, $definition, $metadataReference),
                default => new Definition(MappedRowSet::class)->setFactory([
                    new Reference(MappedTableAdapter::class),
                    'createDefined',
                ])->setArguments([$metadataReference]),
            };
            $container->setDefinition($class, $rowSet);
        }

        $schedule = MappedTableDependencies::compile($mappings);
        $container->setParameter(self::MAPPINGS, $mappings);
        $container
            ->register(MappedTableUnitOfWork::class, MappedTableUnitOfWork::class)
            ->setArguments([$rowSets, new Reference(MappedTableAdapter::class), $schedule])
            ->addTag(TagNames::UNIT_OF_WORK);
    }

    private function postgresRowSet(
        MappedRowSetMapping $mapping,
        MappedRowSetDefinition $definition,
        Reference $metadata,
    ): Definition {
        $configuredTypes = $definition instanceof PostgresMappedRowSetConfiguration
            ? $definition->postgresColumnTypes()
            : [];
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
            $sql->columnTypes,
        ]);

        return new Definition(MappedRowSet::class)->setFactory([
            new Reference(MappedTableAdapter::class),
            'createCompiled',
        ])->setArguments([$metadata, $compiledSql]);
    }
}
