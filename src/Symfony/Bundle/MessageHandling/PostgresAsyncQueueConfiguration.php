<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\MessageHandling;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueue;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueueSchema;

/** @internal */
final class PostgresAsyncQueueConfiguration
{
    public static function configure(DefinitionConfigurator $definition): void
    {
        $definition
            ->rootNode()
            ->children()
            ->arrayNode('async_queue')
            ->children()
            ->arrayNode('postgres')
            ->children()
            ->scalarNode('table_prefix')
            ->defaultValue(PostgresAsyncQueue::DEFAULT_TABLE_PREFIX);
    }

    /** @param array<array-key, mixed> $config */
    public static function load(array $config, ContainerBuilder $container): void
    {
        $prefix = $config['table_prefix'] ?? null;
        if (!is_string($prefix)) {
            throw new \InvalidArgumentException('The PostgreSQL async queue table prefix must be a string.');
        }

        $container
            ->register('ultra.async_queue.postgres.schema', PostgresAsyncQueueSchema::class)
            ->setArguments([$prefix])
            ->addTag('ultra.schema.fragment', ['method' => 'definition']);
    }
}
