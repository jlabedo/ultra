<?php

declare(strict_types=1);

namespace Ultra\Projection\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection\ProjectingEventStore;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;

/** @internal Checks references once Symfony has resolved autowiring, aliases and decorators. */
final class ProjectionPersistenceCompilerPass implements CompilerPassInterface
{
    public const string CONNECTION_PROVIDERS = 'ultra.projection.compiling.connection_providers';

    public function process(ContainerBuilder $container): void
    {
        /** @var array<int, string> $providers */
        $providers = $container->getParameter(self::CONNECTION_PROVIDERS);
        $container->getParameterBag()->remove(self::CONNECTION_PROVIDERS);
        $services = [
            ProjectionImplementationInterface::class => [PostgresProjectionImplementation::class],
            ProjectingEventStore::class . '.inner' => [
                PostgresEventStoreQueryStreams::class,
                PostgresEventStoreHStore::class,
            ],
        ];
        foreach ($providers as $provider) {
            $services[$provider] = [];
        }

        $firstConnection = null;
        $firstService = null;
        foreach ($services as $id => $classes) {
            if (!$container->has($id)) {
                continue;
            }
            $definition = $container->findDefinition($id);
            if (null !== $definition->getFactory() || [] !== $classes && !in_array($definition->getClass(), $classes, true)) {
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
