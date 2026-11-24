<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\Coordination;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\Coordination_Postgres\PostgresCoordinationSchema;

/** @internal */
final class PostgresCoordinationConfiguration
{
    private const array TABLES = ['dirty_keys', 'leases', 'refresh_revisions'];

    public static function configure(DefinitionConfigurator $definition): void
    {
        $postgres = $definition
            ->rootNode()
            ->children()
            ->arrayNode('coordination')
            ->children()
            ->arrayNode('postgres')
            ->children();
        foreach (self::TABLES as $key) {
            $postgres->scalarNode($key)->cannotBeEmpty();
        }
    }

    /** @param array<array-key, mixed> $config */
    public static function load(array $config, ContainerBuilder $container): void
    {
        $tables = [];
        foreach (self::TABLES as $key) {
            $name = $config[$key] ?? null;
            if (null !== $name && !is_string($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'The PostgreSQL coordination table "%s" must be a string.',
                    $key,
                ));
            }
            $tables[] = $name;
        }

        $container
            ->register('ultra.coordination.postgres.schema', PostgresCoordinationSchema::class)
            ->setArguments($tables)
            ->addTag('ultra.schema.fragment', ['method' => 'definition']);
    }
}
