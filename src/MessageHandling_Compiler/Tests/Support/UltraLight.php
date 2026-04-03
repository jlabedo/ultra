<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;

const ULTRA_LIGHT_BUILD_DIR = __DIR__ . '/../../../../var/message_handling_compiler_tests';

final readonly class UltraLight
{
    /**
     * @param array<int|string, object> $services
     * @param list<class-string>        $classesToDiscover
     * @param list<class-string>        $eventClasses
     * @param array<string, string>     $publicAliases
     */
    public function __construct(
        private array $services = [],
        private array $classesToDiscover = [],
        private array $eventClasses = [],
        private array $publicAliases = [
            'test.event_bus' => EventBusInterface::class,
            'test.mediator' => MediatorInterface::class,
        ],
    ) {}

    /**
     * @param class-string ...$classesToDiscover
     */
    public function withClass(string ...$classesToDiscover): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: [...$this->classesToDiscover, ...$classesToDiscover],
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
        );
    }

    /**
     * @param class-string ...$eventClasses
     */
    public function withEventClass(string ...$eventClasses): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: [...$this->eventClasses, ...$eventClasses],
            publicAliases: $this->publicAliases,
        );
    }

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
        );
    }

    public function withAlias(string $alias, string $serviceId): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: [...$this->publicAliases, $alias => $serviceId],
        );
    }

    public function boot(): Container
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.build_dir', ULTRA_LIGHT_BUILD_DIR);

        foreach ($this->classesToDiscover as $class) {
            $container->register($this->serviceIdForClass($class), $class)
                ->setAutoconfigured(true)
                ->setAutowired(false)
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

        new MessageHandlingBundle()->build($container);
        $container->compile();

        foreach ($primaryServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    public function mediator(): MediatorInterface
    {
        /** @var MediatorInterface $mediator */
        $mediator = $this->boot()->get('test.mediator');

        return $mediator;
    }

    public function eventBus(): EventBusInterface
    {
        /** @var EventBusInterface $eventBus */
        $eventBus = $this->boot()->get('test.event_bus');

        return $eventBus;
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
