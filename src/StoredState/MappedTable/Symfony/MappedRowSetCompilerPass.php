<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedColumn;
use Ultra\MappedTable\MappedRowSet;
use Ultra\MappedTable\MappedRowSetAdapter;
use Ultra\MappedTable\MappedRowSetAdapterFactory;
use Ultra\MappedTable\MappedRowSetDefinition;
use Ultra\MappedTable\MappedRowSetMapping;
use Ultra\MappedTable\PostgresMappedColumnType;
use Ultra\MappedTable\PostgresMappedRowSetAdapterFactory;
use Ultra\MappedTable\PostgresMappedRowSetConfiguration;

/** @internal */
final class MappedRowSetCompilerPass implements CompilerPassInterface
{
    public const string DEFINITION_TAG = 'ultra.mapped_table.definition';

    public function process(ContainerBuilder $container): void
    {
        $resources = $container->findTaggedResourceIds(self::DEFINITION_TAG);
        if ([] === $resources) {
            return;
        }

        $factory = $container->findDefinition(MappedRowSetAdapterFactory::class);
        $usesPostgres = PostgresMappedRowSetAdapterFactory::class === $factory->getClass();
        $compiledClasses = [];
        $mappedTables = [];
        foreach ($resources as $id => $tags) {
            $class = $tags[0]['class'] ?? $container->findDefinition($id)->getClass();
            if (!is_string($class) || !is_a($class, MappedRowSetDefinition::class, true)) {
                throw new \LogicException(sprintf(
                    'Mapped row set resource "%s" must implement MappedRowSetDefinition.',
                    $id,
                ));
            }
            if (isset($compiledClasses[$class])) {
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
            if (isset($mappedTables[$mapping->table])) {
                throw new \LogicException(sprintf(
                    'Table "%s" has more than one mapped row set definition.',
                    $mapping->table,
                ));
            }
            $mappedTables[$mapping->table] = true;
            $compiledClasses[$class] = true;

            $adapterArguments = [$mapping->table, $mapping->keyColumns];
            if ($usesPostgres && $definition instanceof PostgresMappedRowSetConfiguration) {
                $types = [];
                foreach ($definition->postgresColumnTypes() as $column => $type) {
                    $mapping->column($column);
                    $types[$column] = new Definition(PostgresMappedColumnType::class)->setFactory([
                        PostgresMappedColumnType::class,
                        'fromSqlType',
                    ])->setArguments([$type->sqlType]);
                }
                $adapterArguments[] = $types;
            }

            $adapter = new Definition(MappedRowSetAdapter::class)->setFactory([
                new Reference(MappedRowSetAdapterFactory::class),
                'create',
            ])->setArguments($adapterArguments);
            $metadata = new Definition(MappedRowSetMapping::class, [
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
            ]);
            $container->setDefinition($class, new Definition(MappedRowSet::class, [$adapter, $metadata]));
        }
    }
}
