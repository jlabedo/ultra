<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\ProjectingEventStore;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;

/** @internal Checks references once Symfony has resolved autowiring, aliases and decorators. */
final class ProjectionPersistenceCompilerPass implements CompilerPassInterface
{
    public const string ROW_SETS = 'ultra.projection.compiling.row_sets';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, list<string>> $projectionRowSets */
        $projectionRowSets = $container->getParameter(self::ROW_SETS);
        $container->getParameterBag()->remove(self::ROW_SETS);

        if (class_exists(MappedTableExtension::class)) {
            $this->validateRowSetInjections($container, $projectionRowSets);
        }
        $this->validatePostgresConnections($container, array_any(
            $projectionRowSets,
            static fn(array $rowSets): bool => [] !== $rowSets,
        ));
    }

    /** @param array<string, list<string>> $projectionRowSets */
    private function validateRowSetInjections(ContainerBuilder $container, array $projectionRowSets): void
    {
        $rowSets = [];
        foreach (array_keys($container->findTaggedServiceIds(MappedTableExtension::ROW_SET_TAG)) as $id) {
            $rowSets[spl_object_id($container->getDefinition($id))] = $id;
        }

        foreach ($projectionRowSets as $id => $declared) {
            foreach ($container->findDefinition($id)->getArguments() as $argument) {
                if (!$argument instanceof Reference || !$container->has((string) $argument)) {
                    continue;
                }
                $rowSet = $rowSets[spl_object_id($container->findDefinition((string) $argument))] ?? null;
                if (null !== $rowSet && !in_array($rowSet, $declared, true)) {
                    throw new \LogicException(sprintf(
                        'Projection "%s" injects mapped row set "%s" and must declare it in rowSets.',
                        $id,
                        $rowSet,
                    ));
                }
            }
        }
    }

    private function validatePostgresConnections(ContainerBuilder $container, bool $withPrivateRowSets): void
    {
        $services = [
            ProjectionImplementationInterface::class => [PostgresProjectionImplementation::class],
            ProjectingEventStore::class . '.inner' => [
                PostgresEventStoreQueryStreams::class,
                PostgresEventStoreHStore::class,
            ],
        ];
        if ($withPrivateRowSets) {
            $services[MappedTableAdapter::class] = [PostgresMappedTableAdapter::class];
        }

        $firstConnection = null;
        $firstService = null;
        foreach ($services as $id => $classes) {
            if (!$container->has($id)) {
                continue;
            }
            $definition = $container->findDefinition($id);
            if (null !== $definition->getFactory() || !in_array($definition->getClass(), $classes, true)) {
                continue;
            }
            $reference = $definition->getArguments()[0] ?? null;
            if (!$reference instanceof Reference || !$container->has((string) $reference)) {
                continue;
            }

            $connection = $container->findDefinition((string) $reference);
            if (!$connection->isShared()) {
                throw new \LogicException(sprintf(
                    'PostgreSQL projection persistence needs a shared connection service, got "%s" for "%s".',
                    (string) $reference,
                    $id,
                ));
            }
            if (null !== $firstConnection && $firstConnection !== $connection) {
                throw new \LogicException(sprintf(
                    'PostgreSQL projection persistence requires the same connection for "%s" and "%s".',
                    $firstService,
                    $id,
                ));
            }
            $firstConnection ??= $connection;
            $firstService ??= $id;
        }
    }
}
