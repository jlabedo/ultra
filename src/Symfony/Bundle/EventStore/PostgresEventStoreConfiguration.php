<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle\EventStore;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\EventStore\EventStore;
use Ultra\EventStore_Postgres\LockStrategy\BucketLockStrategy;
use Ultra\EventStore_Postgres\LockStrategy\SharedExclusiveLockStrategy;
use Ultra\EventStore_Postgres\PostgresEventStoreHStore;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreams;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreSchema;

/** @internal */
final class PostgresEventStoreConfiguration
{
    private const string PREFIX = '.ultra.event_store.postgres.table_prefix';
    private const string STORE = 'ultra.event_store.postgres.store';
    private const string SCHEMA = 'ultra.event_store.postgres.schema';
    private const string STRATEGY = 'ultra.event_store.postgres.lock_strategy';

    private const array VARIANTS = [
        'hstore' => [PostgresEventStoreHStore::class, PostgresEventStoreHStoreSchema::class],
        'query-streams' => [PostgresEventStoreQueryStreams::class, PostgresEventStoreQueryStreamsSchema::class],
    ];

    private const array STRATEGIES = [
        'shared-exclusive' => SharedExclusiveLockStrategy::class,
        'bucket' => BucketLockStrategy::class,
    ];

    public static function configure(DefinitionConfigurator $definition): void
    {
        $postgres = $definition
            ->rootNode()
            ->children()
            ->arrayNode('event_store')
            ->children()
            ->arrayNode('postgres')
            ->children();
        $postgres->enumNode('variant')->values(array_keys(self::VARIANTS))->isRequired();
        $postgres->scalarNode('connection')->isRequired()->cannotBeEmpty();
        $postgres->scalarNode('table_prefix')->defaultValue(PostgresEventStoreSchema::DEFAULT_TABLE_PREFIX);
        $postgres->scalarNode('lock_strategy')->defaultValue('shared-exclusive')->cannotBeEmpty();
    }

    /** @param array<array-key, mixed> $config */
    public static function load(array $config, ContainerBuilder $container): void
    {
        $variant = $config['variant'] ?? null;
        $connection = $config['connection'] ?? null;
        $prefix = $config['table_prefix'] ?? null;
        $strategy = $config['lock_strategy'] ?? null;
        if (
            !is_string($variant)
            || !isset(self::VARIANTS[$variant])
            || !is_string($connection)
            || !is_string($prefix)
            || !is_string($strategy)
        ) {
            throw new \InvalidArgumentException('Invalid PostgreSQL Event Store configuration.');
        }

        [$storeClass, $schemaClass] = self::VARIANTS[$variant];
        $container->setParameter(self::PREFIX, $prefix);
        $prefix = '%' . self::PREFIX . '%';

        if (isset(self::STRATEGIES[$strategy])) {
            $container->register(self::STRATEGY, self::STRATEGIES[$strategy]);
            $strategy = self::STRATEGY;
        }
        $lockStrategy = new Reference($strategy);

        $container
            ->register(self::STORE, $storeClass)
            ->setArguments([
                new Reference($connection),
                $prefix,
                '$lockStrategy' => $lockStrategy,
            ]);
        $container->setAlias(EventStore::class, self::STORE);
        $container
            ->register(self::SCHEMA, $schemaClass)
            ->setArguments([$prefix, $lockStrategy])
            ->addTag('ultra.schema.fragment', ['method' => 'definition']);
    }
}
