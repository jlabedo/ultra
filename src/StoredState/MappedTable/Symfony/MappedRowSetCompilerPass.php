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
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\Plan\RowSetDependencies;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\Storage\PostgresRowSetSql;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\UnitOfWork\TagNames;

/** @internal */
final class MappedRowSetCompilerPass implements CompilerPassInterface
{
    public const string DEFINITION_TAG = 'ultra.mapped_table.definition';

    public const string MAPPINGS = 'ultra.mapped_table.compiling.mappings';
    public const string COORDINATORS = 'ultra.mapped_table.compiling.coordinators';
    public const string PRIVATE_COORDINATOR_TAG = 'ultra.mapped_table.private_coordinator';
    public const string SCHEMA_ROW_SETS = 'ultra.mapped_table.compiling.schema_row_sets';

    public function process(ContainerBuilder $container): void
    {
        $resources = $container->findTaggedResourceIds(self::DEFINITION_TAG);
        $selections = [];
        if ($container->hasParameter(self::SCHEMA_ROW_SETS)) {
            /** @var list<SchemaMappedRowSet> $selections */
            $selections = $container->getParameter(self::SCHEMA_ROW_SETS);
            $container->getParameterBag()->remove(self::SCHEMA_ROW_SETS);
        }
        if ([] === $resources && [] === $selections) {
            $container->setParameter(self::MAPPINGS, []);
            $container->setParameter(self::COORDINATORS, $this->coordinators($container, []));

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
        $postgres = is_a($adapterClass, PostgresMappedTableAdapter::class, true);

        $mappings = [];
        $codecs = [];
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
            $mappings[$class] = $definition->mapping();
            if ($postgres && $definition instanceof PostgresMappedRowSetConfiguration) {
                $codecs[$class] = $definition->postgresColumnTypes();
            }
        }

        if ([] !== $selections) {
            $tables = SchemaExtension::compiledSchema($container)->tablesById();
            foreach ($selections as $selection) {
                $id = $selection->table;
                if (isset($mappings[$id]) || $container->has($id)) {
                    throw new \LogicException(sprintf(
                        'Mapped schema table "%s" conflicts with another service or row set.',
                        $id,
                    ));
                }
                [$mappings[$id], $codecs[$id]] = $selection->compile($tables, $postgres);
            }
        }

        $rowSets = [];
        foreach ($mappings as $id => $mapping) {
            $rowSets[$id] = new Reference($id);
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
            $rowSet = $postgres
                ? $this->postgresRowSet($mapping, $codecs[$id] ?? [], $metadata)
                : new Definition(MappedRowSet::class)->setFactory([
                    new Reference(MappedTableAdapter::class),
                    'createDefined',
                ])->setArguments([$metadata]);
            $container->setDefinition($id, $rowSet->addTag(MappedTableExtension::ROW_SET_TAG));
        }

        $coordinators = $this->coordinators($container, $mappings);
        $schedules = [MappedTableUnitOfWork::class => []];
        foreach (RowSetDependencies::compile($mappings) as $rowSet => $referenced) {
            $coordinator = $coordinators[$rowSet];
            $parent = $mappings[$rowSet]->owner?->definition;
            if (null !== $parent && $coordinator !== $coordinators[$parent]) {
                throw new \LogicException(sprintf(
                    'Mapped row set "%s" and its owner "%s" must use the same coordinator.',
                    $rowSet,
                    $parent,
                ));
            }
            $schedules[$coordinator][$rowSet] = $referenced;
        }

        $container->setParameter(self::MAPPINGS, $mappings);
        $container->setParameter(self::COORDINATORS, $coordinators);
        $container
            ->register(MappedTableUnitOfWork::class, MappedTableUnitOfWork::class)
            ->addTag(TagNames::UNIT_OF_WORK);
        foreach ($schedules as $id => $schedule) {
            $container
                ->getDefinition($id)
                ->setArguments([
                    array_intersect_key($rowSets, $schedule),
                    new Reference(MappedTableAdapter::class),
                    $schedule,
                ]);
        }
    }

    /**
     * @param array<string, MappedRowSetMapping> $mappings
     * @return array<string, string>
     */
    private function coordinators(ContainerBuilder $container, array $mappings): array
    {
        $coordinators = array_fill_keys(array_keys($mappings), MappedTableUnitOfWork::class);
        foreach ($container->findTaggedServiceIds(self::PRIVATE_COORDINATOR_TAG) as $id => $tags) {
            $tag = $tags[0] ?? null;
            if (!is_array($tag) || !is_array($tag['rowSets'] ?? null) || [] === $tag['rowSets']) {
                throw new \LogicException(sprintf('Private coordinator "%s" must declare its mapped row sets.', $id));
            }
            foreach ($tag['rowSets'] as $rowSet) {
                if (!is_string($rowSet) || !isset($mappings[$rowSet])) {
                    throw new \LogicException(sprintf(
                        'Private coordinator "%s" requires a registered mapped row set definition, got "%s".',
                        $id,
                        is_string($rowSet) ? $rowSet : get_debug_type($rowSet),
                    ));
                }
                if (MappedTableUnitOfWork::class !== $coordinators[$rowSet] && $id !== $coordinators[$rowSet]) {
                    throw new \LogicException(sprintf(
                        'Mapped row set "%s" must have a single owner, claimed by "%s" and "%s".',
                        $rowSet,
                        $coordinators[$rowSet],
                        $id,
                    ));
                }
                $coordinators[$rowSet] = $id;
            }
        }

        return $coordinators;
    }

    /** @param array<string, PostgresMappedColumnType> $configuredTypes */
    private function postgresRowSet(
        MappedRowSetMapping $mapping,
        array $configuredTypes,
        Definition $metadata,
    ): Definition {
        $sql = PostgresRowSetSql::compile($mapping, $configuredTypes);
        $compiledSql = new Definition(PostgresRowSetSql::class, [
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
