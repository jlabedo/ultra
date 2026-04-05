<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ultra\MessageHandling\Async\AsyncQueueInterface;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\Attribute\Advice;
use Ultra\MessageHandling\Attribute\EventHandler;
use Ultra\MessageHandling\Attribute\Handles;
use Ultra\MessageHandling\Attribute\SelfHandler;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;
use Ultra\MessageHandling\MessageHandlingBundle;
use Ultra\MessageHandling\TagNames;

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
            'test.async_queue' => AsyncQueueInterface::class,
            'test.queue_worker' => QueueWorker::class,
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
            $definition = $container->register($this->serviceIdForClass($class), $class)
                ->setAutowired(false)
            ;

            if (str_contains($class, "\0")) {
                $this->addTagsFromAttributes($definition, $class);
            } else {
                $definition->setAutoconfigured(true);
            }
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

    public function asyncQueue(): AsyncQueueInterface
    {
        /** @var AsyncQueueInterface $queue */
        $queue = $this->boot()->get('test.async_queue');

        return $queue;
    }

    public function queueWorker(): QueueWorker
    {
        /** @var QueueWorker $worker */
        $worker = $this->boot()->get('test.queue_worker');

        return $worker;
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

            $definition = $container->register($primaryId, $service::class)
                ->setSynthetic(true)
            ;

            // Anonymous classes in PHP 8.5 have \0 in their names. Using
            // setAutoconfigured(true) would cause Symfony to create
            // ".instanceof." child definitions with the raw class name,
            // which fails validation. Instead, manually detect attributes
            // and add tags directly.
            if (str_contains($service::class, "\0")) {
                $this->addTagsFromAttributes($definition, $service::class);
            } else {
                $definition->setAutoconfigured(true);
            }

            $primaryServices[$primaryId] = $service;
        }

        return $primaryServices;
    }

    /**
     * Manually discover handler/aspect attributes on a class and add
     * the corresponding DI tags, bypassing autoconfiguration.
     *
     * @param class-string $class
     */
    private function addTagsFromAttributes(
        \Symfony\Component\DependencyInjection\Definition $definition,
        string $class,
    ): void {
        $ref = new \ReflectionClass($class);
        $hasAspect = false;

        foreach ($ref->getMethods() as $method) {
            if ([] !== $method->getAttributes(Handles::class)
                || [] !== $method->getAttributes(EventHandler::class)
            ) {
                $definition->addTag(TagNames::HANDLER, ['method' => $method->getName()]);
            }
            if ([] !== $method->getAttributes(SelfHandler::class)) {
                $definition->addResourceTag(TagNames::SELF_HANDLER, ['method' => $method->getName()]);
            }
            if (! $hasAspect && [] !== $method->getAttributes(Advice::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                $hasAspect = true;
            }
        }

        if ($hasAspect) {
            $definition->addTag(TagNames::ASPECT);
        }
    }

    /**
     * @param class-string $class
     */
    private function serviceIdForClass(string $class): string
    {
        return str_replace("\0", '', $class);
    }
}
