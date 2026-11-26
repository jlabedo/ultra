<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\Codec\ArrayCodec;
use Ultra\MappedTable\Codec\IntegerCodec;
use Ultra\MappedTable\Codec\TemporalCodec;
use Ultra\MappedTable\Codec\TextCodec;
use Ultra\MappedTable\ColumnCodec;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\MappedRowSetMetadata;
use Ultra\MappedTable\MappedRowSetOwner;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\MappedTableUnitOfWork;
use Ultra\MappedTable\Plan\RowSetDependencies;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\Schema\SchemaRowSet;
use Ultra\MappedTable\Storage\PostgresRowSetSql;
use Ultra\Schema\Symfony\SchemaExtension;
use Ultra\UnitOfWork\TagNames;

/** @internal */
final class MappedRowSetCompilerPass implements CompilerPassInterface
{
    public const string MAPPINGS = 'ultra.mapped_table.compiling.mappings';
    public const string COORDINATORS = 'ultra.mapped_table.compiling.coordinators';
    public const string FACTORY_TAG = 'ultra.mapped_table.row_set_factory';
    public const string ROW_SETS = 'ultra.mapped_table.compiling.row_sets';

    public function process(ContainerBuilder $container): void
    {
        $selections = $this->selections($container);
        if ([] === $selections) {
            $container->setParameter(self::MAPPINGS, []);
            $container->setParameter(self::COORDINATORS, []);

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

        $coordinators = [];
        $mappings = [];
        $rowSets = [];
        foreach ($selections as [$id, $source, $coordinator]) {
            if ('' === $id || is_numeric($id) || '' === $coordinator || is_numeric($coordinator)) {
                throw new \InvalidArgumentException(
                    'Row sets and their coordinators need non-empty, non-numeric service IDs.',
                );
            }
            if ($container->has($id)) {
                throw new \LogicException(sprintf(
                    'Mapped row set "%s" conflicts with another service or row set.',
                    $id,
                ));
            }
            if (is_array($source)) {
                if (!class_exists(SchemaExtension::class)) {
                    throw new \LogicException(sprintf('Schema-backed row set "%s" requires ultra/schema.', $id));
                }
                $source = SchemaRowSet::fromTable(SchemaExtension::compiledSchema($container), $id, ...$source);
            }
            $services = [];
            foreach ($source->codecs as $codec) {
                if (!is_string($codec)) {
                    continue;
                }
                $class = $container->getParameterBag()->resolveValue($container->findDefinition($codec)->getClass());
                if (!is_string($class) || !is_a($class, ColumnCodec::class, true)) {
                    throw new \LogicException(sprintf('Codec service "%s" must declare a ColumnCodec class.', $codec));
                }
                $services[$codec] = $class;
            }
            $mapping = $source->compile($services);
            $coordinators[$id] = $coordinator;
            $mappings[$id] = $mapping;
            $rowSets[$id] = new Reference($id);
            $columns = [];
            foreach ($mapping->columns as $name => $column) {
                $codec = $source->codecs[$name] ?? null;
                $arguments = [$column->nullable];
                if (null !== $codec) {
                    $arguments[] = is_string($codec) ? new Reference($codec) : $this->inlineCodec($codec);
                }
                $columns[$name] = new Definition(MappedColumn::class, $arguments);
            }
            $metadata = new Definition(MappedRowSetMetadata::class, [
                $mapping->table,
                $columns,
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
                ? $this->postgresRowSet($container, $mapping, $metadata)
                : new Definition(MappedRowSet::class)->setFactory([
                    new Reference(MappedTableAdapter::class),
                    'createDefined',
                ])->setArguments([$metadata]);
            $container->setDefinition($id, $rowSet);
        }

        $groups = [MappedTableUnitOfWork::class => []];
        foreach ($mappings as $rowSet => $mapping) {
            $coordinator = $coordinators[$rowSet];
            $parent = $mapping->owner?->definition;
            if (null !== $parent && isset($coordinators[$parent]) && $coordinator !== $coordinators[$parent]) {
                throw new \LogicException(sprintf(
                    'Mapped row set "%s" and its owner "%s" must use the same coordinator.',
                    $rowSet,
                    $parent,
                ));
            }
            $groups[$coordinator][$rowSet] = $mapping;
        }
        $container->setParameter(self::MAPPINGS, $mappings);
        $container->setParameter(self::COORDINATORS, $coordinators);
        foreach ($groups as $id => $group) {
            $schedule = RowSetDependencies::compile($group);
            if ($container->has($id)) {
                throw new \LogicException(sprintf(
                    'Mapped table coordinator "%s" conflicts with an existing service.',
                    $id,
                ));
            }
            $unit = $container->register($id, MappedTableUnitOfWork::class);
            if (MappedTableUnitOfWork::class === $id) {
                $unit->addTag(TagNames::UNIT_OF_WORK);
            }
            if ($postgres) {
                $unit->addTag(TagNames::DBAL_CONNECTION_PROVIDER, ['service' => MappedTableAdapter::class]);
            }
            $unit->setArguments([
                array_intersect_key($rowSets, $schedule),
                new Reference(MappedTableAdapter::class),
                $schedule,
            ]);
        }
    }

    /** @return list<array{string, MappedRowSetDefinition|array{columns?: list<string>, owner?: string, ownerColumns?: list<string>, codecs?: array<string, ColumnCodec|string>}, string}> */
    private function selections(ContainerBuilder $container): array
    {
        /** @var list<array{string, MappedRowSetDefinition|array{columns?: list<string>, owner?: string, ownerColumns?: list<string>, codecs?: array<string, ColumnCodec|string>}, string}> $selections */
        $selections = $container->getParameter(self::ROW_SETS);
        $container->getParameterBag()->remove(self::ROW_SETS);
        foreach ($container->findTaggedServiceIds(self::FACTORY_TAG, throwOnAbstract: true) as $service => $tags) {
            $class = $container->getDefinition($service)->getClass();
            $reflection = $container->getReflectionClass($class);
            foreach ($tags as $tag) {
                if (
                    !is_array($tag)
                    || !is_string($tag['method'] ?? null)
                    || !is_string($tag['id'] ?? null)
                    || !is_string($tag['unitOfWork'] ?? null)
                ) {
                    throw new \LogicException(sprintf('Invalid mapped row set factory tag on "%s".', $service));
                }
                $method = $reflection?->getMethod($tag['method']);
                if (
                    null === $method
                    || !$method->isPublic()
                    || !$method->isStatic()
                    || $method->isAbstract()
                    || 0 !== $method->getNumberOfParameters()
                ) {
                    throw new \LogicException(sprintf(
                        'Row set factory "%s::%s" must be public, concrete, static and parameterless.',
                        $class,
                        $tag['method'],
                    ));
                }
                $definition = $method->invoke(null);
                if (!$definition instanceof MappedRowSetDefinition) {
                    throw new \LogicException(sprintf(
                        'Row set factory "%s::%s" must return a MappedRowSetDefinition.',
                        $class,
                        $method->getName(),
                    ));
                }
                $selections[] = [$tag['id'], $definition, $tag['unitOfWork']];
            }
        }

        return $selections;
    }

    private function inlineCodec(ColumnCodec $codec): Definition
    {
        $arguments = match (true) {
            $codec instanceof ArrayCodec => [$this->inlineCodec($codec->element)],
            $codec instanceof IntegerCodec => [$codec->int32],
            $codec instanceof TemporalCodec => [$codec->format],
            $codec instanceof TextCodec => [$codec->maxLength, $codec->stringableKeys],
            [] === get_mangled_object_vars($codec)
                && 0 === (new \ReflectionClass($codec)->getConstructor()?->getNumberOfRequiredParameters() ?? 0)
                => [],
            default => throw new \LogicException(sprintf(
                'Register the configured codec %s as a Symfony service and use its ID for the column.',
                $codec::class,
            )),
        };

        return new Definition($codec::class, $arguments);
    }

    private function postgresRowSet(
        ContainerBuilder $container,
        MappedRowSetMapping $mapping,
        Definition $metadata,
    ): Definition {
        $sql = PostgresRowSetSql::compile($mapping);
        $compiledSql = new Definition(PostgresRowSetSql::class, array_map(
            $container->getParameterBag()->escapeValue(...),
            [
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
            ],
        ));

        return new Definition(MappedRowSet::class)->setFactory([
            new Reference(MappedTableAdapter::class),
            'createCompiled',
        ])->setArguments([$metadata, $compiledSql]);
    }
}
