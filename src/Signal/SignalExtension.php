<?php

declare(strict_types=1);

namespace Ultra\Signal;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ultra\Container\ContainerExtension;
use Ultra\Time\Duration;

final class SignalExtension implements ContainerExtension
{
    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(SignalHandler::class)
            ->addTag(TagNames::SIGNAL_HANDLER)
        ;

        if (!$container->hasParameter('ultra.signal.per_queue_limit')) {
            $container->setParameter('ultra.signal.per_queue_limit', 64);
        }

        if (!$container->hasParameter('ultra.signal.delivery_timeout_seconds')) {
            $container->setParameter('ultra.signal.delivery_timeout_seconds', 5);
        }

        if (!$container->hasParameter('ultra.signal.worker_id')) {
            $container->setParameter('ultra.signal.worker_id', null);
        }

        if (!$container->has(SignalStore::class)) {
            return;
        }

        $container->register(SignalPoller::class, SignalPoller::class)
            ->setArguments([
                new Reference(SignalStore::class),
                new TaggedIteratorArgument(TagNames::SIGNAL_HANDLER),
                '%ultra.signal.per_queue_limit%',
                new Definition(Duration::class, [0, '%ultra.signal.delivery_timeout_seconds%']),
                '%ultra.signal.worker_id%',
            ])
        ;
    }
}
