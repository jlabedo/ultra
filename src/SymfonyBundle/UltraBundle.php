<?php

declare(strict_types=1);

namespace Ultra\SymfonyBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Ultra\Container\ContainerExtension;

final class UltraBundle extends AbstractBundle
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
        $extensionClasses = [
            'Ultra\\Events\\EventsExtension',
            'Ultra\\MessageHandling\\MessageHandlingExtension',
            'Ultra\\UnitOfWork\\UnitOfWorkExtension',
            'Ultra\\Mapper_Bundle\\MapperExtension',
            'Ultra\\EventSourcedState\\EventSourcedStateExtension',
            'Ultra\\StateStore_Bundle\\StateStoreExtension',
            'Ultra\\StatefulMessageHandling\\StatefulMessageHandlingExtension',
            'Ultra\\Projection\\Bundle\\ProjectionExtension',
        ];

        $extensions = [];
        foreach ($extensionClasses as $extensionClass) {
            if (!class_exists($extensionClass)) {
                continue;
            }

            $extension = new $extensionClass();
            if (!$extension instanceof ContainerExtension) {
                throw new \LogicException(sprintf(
                    'Ultra extension "%s" must implement "%s".',
                    $extensionClass,
                    ContainerExtension::class,
                ));
            }

            $extensions[] = $extension;
        }

        return $extensions;
    }
}
