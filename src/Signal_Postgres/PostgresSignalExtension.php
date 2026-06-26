<?php

declare(strict_types=1);

namespace Ultra\Signal_Postgres;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\Signal\SignalStore;

final class PostgresSignalExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('ultra.signal_postgres.table_prefix')) {
            $container->setParameter('ultra.signal_postgres.table_prefix', 'ultra_');
        }

        $container->register(PostgresSignalSchema::class, PostgresSignalSchema::class)
            ->setArguments(['%ultra.signal_postgres.table_prefix%'])
        ;

        if (!class_exists(Connection::class) || !$container->has(Connection::class) || $container->has(SignalStore::class)) {
            return;
        }

        $container->register(PostgresSignalStore::class, PostgresSignalStore::class)
            ->setArguments([
                new Reference(Connection::class),
                '%ultra.signal_postgres.table_prefix%',
            ])
        ;

        $container->setAlias(SignalStore::class, PostgresSignalStore::class);
    }
}
