<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\Container\ContainerExtension;
use Ultra\EventSourcedState\EventSourcedStateExtension;
use Ultra\Events\EventsExtension;
use Ultra\Mapper_Bundle\MapperExtension;
use Ultra\MessageHandling\MessageHandlingExtension;
use Ultra\StatefulMessageHandling\StatefulMessageHandlingExtension;
use Ultra\StateStore_Bundle\StateStoreExtension;
use Ultra\UnitOfWork\UnitOfWorkExtension;

final class UltraBundle extends Bundle
{
    /**
     * @param list<ContainerExtension> $extensions
     */
    public function __construct(
        private readonly array $extensions = [],
    ) {
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        foreach ($this->extensions ?: self::defaultExtensions() as $extension) {
            $extension->build($container);
        }
    }

    /**
     * @return list<ContainerExtension>
     */
    public static function defaultExtensions(): array
    {
        return [
            new EventsExtension(),
            new MessageHandlingExtension(),
            new UnitOfWorkExtension(),
            new MapperExtension(),
            new EventSourcedStateExtension(),
            new StateStoreExtension(),
            new StatefulMessageHandlingExtension(),
        ];
    }
}
