<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork_Bundle;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\UnitOfWork;

final class UnitOfWorkBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(UnitOfWork::class)
            ->addTag(TagNames::UNIT_OF_WORK)
        ;

        $container->register(EventStoreUnitOfWork::class, EventStoreUnitOfWork::class)
            ->setDecoratedService(EventStore::class)
            ->setArguments([
                new Reference(EventStoreUnitOfWork::class.'.inner'),
            ])
            ->addTag(TagNames::UNIT_OF_WORK)
        ;

        $container->register(CompositeUnitOfWork::class, CompositeUnitOfWork::class)
            ->setArguments([
                new TaggedIteratorArgument(TagNames::UNIT_OF_WORK),
            ])
        ;

        $container->setAlias(UnitOfWork::class, CompositeUnitOfWork::class);
    }
}
