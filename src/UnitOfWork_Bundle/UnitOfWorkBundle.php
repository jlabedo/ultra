<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork_Bundle;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\EventStoreUnitOfWork;
use Ultra\UnitOfWork\ApplicationLifecycle;
use Ultra\UnitOfWork\Attribute\FlushAfterCommit;
use Ultra\UnitOfWork\Attribute\FlushBeforeCommit;
use Ultra\UnitOfWork\CompositeUnitOfWork;
use Ultra\UnitOfWork\NoOpTransactionManager;
use Ultra\UnitOfWork\TransactionManager;
use Ultra\UnitOfWork\UnitOfWork;
use Ultra\UnitOfWork\UnitOfWorkLifecycle;

final class UnitOfWorkBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(UnitOfWork::class)
            ->addTag(TagNames::UNIT_OF_WORK)
        ;

        $container->registerAttributeForAutoconfiguration(
            FlushBeforeCommit::class,
            static function (ChildDefinition $definition, FlushBeforeCommit $attribute): void {
                $definition->clearTag(TagNames::UNIT_OF_WORK);
                $definition->addTag(TagNames::UNIT_OF_WORK, ['priority' => $attribute->priority]);
            }
        );

        $container->registerAttributeForAutoconfiguration(
            FlushAfterCommit::class,
            static function (ChildDefinition $definition, FlushAfterCommit $attribute): void {
                $definition->clearTag(TagNames::UNIT_OF_WORK);
                $definition->addTag(TagNames::AFTER_COMMIT_UNIT_OF_WORK, ['priority' => $attribute->priority]);
            }
        );

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

        $container->register('ultra_unit_of_work.after_commit_composite', CompositeUnitOfWork::class)
            ->setArguments([
                new TaggedIteratorArgument(TagNames::AFTER_COMMIT_UNIT_OF_WORK),
            ])
        ;

        $container->register(UnitOfWorkLifecycle::class, UnitOfWorkLifecycle::class)
            ->setArguments([
                new Reference(CompositeUnitOfWork::class),
                new Reference('ultra_unit_of_work.after_commit_composite'),
            ])
        ;

        if (!$container->has(TransactionManager::class)) {
            $container->register(TransactionManager::class, NoOpTransactionManager::class);
        }

        $container->register(ApplicationLifecycle::class, ApplicationLifecycle::class)
            ->setArguments([
                new Reference(TransactionManager::class),
                new Reference(UnitOfWorkLifecycle::class),
            ])
        ;

        $container->setAlias(UnitOfWork::class, CompositeUnitOfWork::class);

        $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->findTaggedServiceIds(TagNames::AFTER_COMMIT_UNIT_OF_WORK) as $serviceId => $_tags) {
                        if (!$container->hasDefinition($serviceId)) {
                            continue;
                        }

                        $container->getDefinition($serviceId)->clearTag(TagNames::UNIT_OF_WORK);
                    }
                }
            }, priority: 100);
    }
}
