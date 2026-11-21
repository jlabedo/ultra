<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use MappedTableSymfonyExample\ReservationsDefinition;
use MappedTableSymfonyExample\WorkshopRegistration;
use MappedTableSymfonyExample\WorkshopsDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\GuardLockAdapter;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PostgresGuardLockAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

$container = new ContainerBuilder();

new UnitOfWorkExtension()->build($container);
new MappedTableExtension([
    new QueryGuardDefinition(WorkshopRegistration::CAPACITY_GUARD, [
        new QueryGuardDependency(ReservationsDefinition::class, 'workshop_id', ['seats']),
    ]),
])->build($container);

$container->register(Connection::class)->setSynthetic(true)->setPublic(true);
$container
    ->register(MappedTableAdapter::class, PostgresMappedTableAdapter::class)
    ->setArguments([new Reference(Connection::class)]);
$container
    ->register(GuardLockAdapter::class, PostgresGuardLockAdapter::class)
    ->setArguments([new Reference(Connection::class)]);
$container
    ->register(TransactionManager::class, DbalTransactionManager::class)
    ->setArguments([new Reference(Connection::class), TransactionIsolationLevel::READ_COMMITTED]);

$container->register(WorkshopsDefinition::class)->setAutoconfigured(true);
$container->register(ReservationsDefinition::class)->setAutoconfigured(true);
$container->register(WorkshopRegistration::class)->setAutowired(true)->setPublic(true);
$container->getAlias(UnitOfWorkRunner::class)->setPublic(true);

return $container;
