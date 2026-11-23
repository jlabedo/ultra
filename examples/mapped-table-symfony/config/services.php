<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use MappedTableSymfonyExample\WorkshopRegistration;
use MappedTableSymfonyExample\WorkshopSchema;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\MappedTable\MappedTableAdapter;
use Ultra\MappedTable\PostgresMappedTableAdapter;
use Ultra\MappedTable\QueryGuardDefinition;
use Ultra\MappedTable\QueryGuardDependency;
use Ultra\MappedTable\Symfony\MappedTableExtension;
use Ultra\MappedTable\Symfony\SchemaMappedRowSet;
use Ultra\UnitOfWork\DbalTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWorkExtension;
use Ultra\UnitOfWork\UnitOfWorkRunner;

$container = new ContainerBuilder();

new UnitOfWorkExtension()->build($container);
new MappedTableExtension(
    queryGuards: [
        new QueryGuardDefinition(WorkshopRegistration::CAPACITY_GUARD, [
            new QueryGuardDependency(WorkshopSchema::RESERVATIONS, 'workshop_id', ['seats']),
        ]),
    ],
    schema: WorkshopSchema::definition(),
    schemaRowSets: [
        new SchemaMappedRowSet(WorkshopSchema::WORKSHOPS),
        new SchemaMappedRowSet(WorkshopSchema::RESERVATIONS, owner: WorkshopSchema::WORKSHOP_REFERENCE),
    ],
)->build($container);

$container->register(Connection::class)->setSynthetic(true)->setPublic(true);
$container
    ->register(MappedTableAdapter::class, PostgresMappedTableAdapter::class)
    ->setArguments([new Reference(Connection::class)]);
$container
    ->register(TransactionManager::class, DbalTransactionManager::class)
    ->setArguments([new Reference(Connection::class), TransactionIsolationLevel::READ_COMMITTED]);

$container->register(WorkshopRegistration::class)->setAutowired(true)->setPublic(true);
$container->getAlias(UnitOfWorkRunner::class)->setPublic(true);

return $container;
