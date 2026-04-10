<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ultra\MessageHandling\Async\AsyncQueueInterface;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;

const ULTRA_LIGHT_BUILD_DIR = __DIR__.'/../../../../var/message_handling_compiler_tests';

final readonly class UltraLight
{
    /**
 * @param array<int|string, object> $services
 * @param list<class-string>        $classesToDiscover
 * @param list<class-string>        $eventClasses
 * @param array<string, string>     $publicAliases
 * @param list<Bundle>              $extraBundles
 */
    public function __construct(
        private array $services = [],
        private array $classesToDiscover = [],
        private array $eventClasses = [],
        private array $publicAliases = [
            'test.event_bus' => EventBusInterface::class,
            'test.mediator' => MediatorInterface::class,
            'test.async_queue' => AsyncQueueInterface::class,
            'test.queue_worker' => QueueWorker::class,
        ],
        private array $extraBundles = [],
        private string $buildDir = ULTRA_LIGHT_BUILD_DIR,
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
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
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
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
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
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
        );
    }

    public function withAlias(string $alias, string $serviceId): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: [...$this->publicAliases, $alias => $serviceId],
            extraBundles: $this->extraBundles,
            buildDir: $this->buildDir,
        );
    }

    public function withBundle(Bundle ...$extraBundles): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: [...$this->extraBundles, ...$extraBundles],
            buildDir: $this->buildDir,
        );
    }

    public function withBuildDir(string $buildDir): self
    {
        return new self(
            services: $this->services,
            classesToDiscover: $this->classesToDiscover,
            eventClasses: $this->eventClasses,
            publicAliases: $this->publicAliases,
            extraBundles: $this->extraBundles,
            buildDir: $buildDir,
        );
    }

    public function boot(): Container
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

        foreach ([new MessageHandlingBundle(), ...$this->extraBundles] as $bundle) {
            $bundle->build($container);
        }
        $container->compile();

        foreach ($primaryServices as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }

    public function mediator(): MediatorInterface
    {
        // @var MediatorInterface $mediator
        return $this->boot()->get('test.mediator');
    }

    public function eventBus(): EventBusInterface
    {
        // @var EventBusInterface $eventBus
        return $this->boot()->get('test.event_bus');
    }

    public function asyncQueue(): AsyncQueueInterface
    {
        // @var AsyncQueueInterface $queue
        return $this->boot()->get('test.async_queue');
    }

    public function queueWorker(): QueueWorker
    {
        // @var QueueWorker $worker
        return $this->boot()->get('test.queue_worker');
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
