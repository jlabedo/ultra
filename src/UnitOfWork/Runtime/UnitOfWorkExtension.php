<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;

final class UnitOfWorkExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(UnitOfWork::class)->addTag(TagNames::UNIT_OF_WORK);

        if (interface_exists(EventStore::class) && class_exists(EventStoreUnitOfWork::class)) {
            $container
                ->register(EventStoreUnitOfWork::class, EventStoreUnitOfWork::class)
                ->setDecoratedService(EventStore::class)
                ->setArguments([
                    new Reference(EventStoreUnitOfWork::class . '.inner'),
                ])
                ->addTag(TagNames::UNIT_OF_WORK);
        }

        $container
            ->register(CompositeUnitOfWork::class, CompositeUnitOfWork::class)
            ->setArguments([
                new TaggedIteratorArgument(TagNames::UNIT_OF_WORK),
            ]);

        $container->setAlias(UnitOfWork::class, CompositeUnitOfWork::class);

        if (!$container->has(TransactionManager::class)) {
            $container->register(NoTransactionManager::class, NoTransactionManager::class);
            $container->setAlias(TransactionManager::class, NoTransactionManager::class);
        }

        $container
            ->register(TransactionalUnitOfWorkRunner::class, TransactionalUnitOfWorkRunner::class)
            ->setArguments([
                new Reference(UnitOfWork::class),
                new Reference(TransactionManager::class),
            ]);

        $container->setAlias(UnitOfWorkRunner::class, TransactionalUnitOfWorkRunner::class);

        if (class_exists('Ultra\\MessageHandling\\TagNames')) {
            $container
                ->getDefinition(TransactionalUnitOfWorkRunner::class)
                ->addTag((string) constant('Ultra\\MessageHandling\\TagNames::ASPECT'));
        }
    }
}
