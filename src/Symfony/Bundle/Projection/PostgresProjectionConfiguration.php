<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\Projection;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Projection\Bundle\ProjectionImplementationInterface;
use Ultra\Projection_Postgres\PostgresProjectionImplementation;
use Ultra\Projection_Postgres\PostgresProjectionSchema;

/** @internal */
final class PostgresProjectionConfiguration
{
    private const string PREFIX = '.ultra.projection.postgres.table_prefix';
    private const string IMPLEMENTATION = 'ultra.projection.postgres.implementation';
    private const string SCHEMA = 'ultra.projection.postgres.schema';

    public static function configure(DefinitionConfigurator $definition): void
    {
        $postgres = $definition
            ->rootNode()
            ->children()
            ->arrayNode('projection')
            ->children()
            ->arrayNode('postgres')
            ->children();
        $postgres->scalarNode('connection')->isRequired()->cannotBeEmpty();
        $postgres->scalarNode('table_prefix')->defaultValue(PostgresProjectionImplementation::DEFAULT_TABLE_PREFIX);
    }

    /** @param array<array-key, mixed> $config */
    public static function load(array $config, ContainerBuilder $container): void
    {
        $connection = $config['connection'] ?? null;
        $prefix = $config['table_prefix'] ?? null;
        if (!is_string($connection) || !is_string($prefix)) {
            throw new \InvalidArgumentException('Invalid PostgreSQL projection configuration.');
        }

        $container->setParameter(self::PREFIX, $prefix);
        $prefix = '%' . self::PREFIX . '%';

        $container
            ->register(self::IMPLEMENTATION, PostgresProjectionImplementation::class)
            ->setAutowired(true)
            ->setArguments([new Reference($connection), $prefix]);
        $container->setAlias(ProjectionImplementationInterface::class, self::IMPLEMENTATION);
        $container
            ->register(self::SCHEMA, PostgresProjectionSchema::class)
            ->setArguments([$prefix])
            ->addTag('ultra.schema.fragment', ['method' => 'definition']);
    }
}
