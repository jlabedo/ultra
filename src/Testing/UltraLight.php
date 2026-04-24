<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

const ULTRA_LIGHT_BUILD_DIR = __DIR__.'/../../var/ultra_light_tests';

/**
 * @template TContainer of UltraLightContainer
 */
final readonly class UltraLight
{
    /** @var UltraLightContainerFactoryInterface<TContainer> */
    private UltraLightContainerFactoryInterface $containerFactory;

    /**
     * @param array<int|string, object>                            $services
     * @param list<class-string>                                   $classesToDiscover
     * @param list<class-string>                                   $eventClasses
     * @param array<string, string>                                $publicAliases
     * @param list<Bundle>                                         $extraBundles
     * @param null|UltraLightContainerFactoryInterface<TContainer> $containerFactory
     */
    public function __construct(
        private array $services = [],
        private array $classesToDiscover = [],
        private array $eventClasses = [],
        private array $publicAliases = [],
        private array $extraBundles = [],
        private string $buildDir = ULTRA_LIGHT_BUILD_DIR,
        ?UltraLightContainerFactoryInterface $containerFactory = null,
    ) {
        /** @var UltraLightContainerFactoryInterface<TContainer> $resolvedContainerFactory */
        $resolvedContainerFactory = $containerFactory ?? new DefaultUltraLightContainerFactory();

        $this->containerFactory = $resolvedContainerFactory;
    }

    /**
     * @param class-string ...$classesToDiscover
     *
     * @return UltraLight<TContainer>
     */
    public function withClass(string ...$classesToDiscover): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: [...$this->classesToDiscover, ...$classesToDiscover],
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
            containerFactory: $this->containerFactory,
        );
    }

    /**
     * @param class-string ...$eventClasses
     *
     * @return UltraLight<TContainer>
     */
    public function withEventClass(string ...$eventClasses): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: [...$this->eventClasses, ...$eventClasses],
            publicAliases: $this->publicAliases,
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
            containerFactory: $this->containerFactory,
        );
    }

    /**
     * @return UltraLight<TContainer>
     */
    public function withService(object $service, ?string $serviceId = null): self
    {
        $services = $this->services;
        if (null === $serviceId) {
            $services[] = $service;
        } else {
            $services[$serviceId] = $service;
        }

        return new self(
            services: $services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
            containerFactory: $this->containerFactory,
        );
    }

    /**
     * @return UltraLight<TContainer>
     */
    public function withAlias(string $alias, string $serviceId): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: [...$this->publicAliases, $alias => $serviceId],
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
            containerFactory: $this->containerFactory,
        );
    }

    /**
     * @return UltraLight<TContainer>
     */
    public function withBundle(Bundle ...$extraBundles): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: [...$this->extraBundles, ...$extraBundles],
            buildDir: $this->buildDir,
            containerFactory: $this->containerFactory,
        );
    }

    /**
     * @return UltraLight<TContainer>
     */
    public function withBuildDir(string $buildDir): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: $this->extraBundles,
            buildDir: $buildDir,
            containerFactory: $this->containerFactory,
        );
    }

    /**
     * @template TNewContainer of UltraLightContainer
     *
     * @param UltraLightContainerFactoryInterface<TNewContainer> $containerFactory
     *
     * @return UltraLight<TNewContainer>
     */
    public function withContainerFactory(UltraLightContainerFactoryInterface $containerFactory): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
            containerFactory: $containerFactory,
        );
    }

    /**
     * @return TContainer
     */
    public function boot(): UltraLightContainer
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', $this->buildDir);

        foreach ($this->classesToDiscover as $class) {
            $container->register($this->serviceIdForClass($class), $class)
                ->setAutoconfigured(true)
                ->setAutowired(true)
            ;
        }

        foreach ($this->eventClasses as $eventClass) {
            $container->register($this->serviceIdForClass($eventClass), $eventClass)
                ->setAutowired(false)
                ->addResourceTag('ultra_event_bus.event')
            ;
        }

        $primaryServices = $this->registerProvidedServices($container);

        foreach ($this->publicAliases as $alias => $serviceId) {
            $container->setAlias($alias, $serviceId)->setPublic(true);
        }

        foreach ($this->extraBundles as $bundle) {
            $bundle->build($container);
        }
        $container->compile();

        foreach ($primaryServices as $id => $service) {
            $container->set($id, $service);
        }

        return $this->containerFactory->create($container);
    }

    /**
     * @return array<string, object>
     */
    private function registerProvidedServices(ContainerBuilder $container): array
    {
        $primaryServices = [];

        foreach ($this->services as $serviceId => $service) {
            $primaryId = is_string($serviceId)
                ? $serviceId
                : $this->serviceIdForClass($service::class);

            if (isset($primaryServices[$primaryId])) {
                throw new \InvalidArgumentException(sprintf(
                    'Service "%s" is already registered as "%s".',
                    $service::class,
                    $primaryId,
                ));
            }

            $container->register($primaryId, $service::class)
                ->setAutoconfigured(true)
                ->setSynthetic(true)
            ;

            $primaryServices[$primaryId] = $service;
        }

        return $primaryServices;
    }

    /**
     * @param class-string $class
     */
    private function serviceIdForClass(string $class): string
    {
        return str_replace("\0", '', $class);
    }
}
