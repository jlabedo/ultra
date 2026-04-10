<?php

declare(strict_types=1);

namespace Ultra\MessageHandling_Compiler\Tests\Support;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Ultra\MessageHandling\Async\AsyncQueueInterface;
use Ultra\MessageHandling\Async\InMemoryQueue;
use Ultra\MessageHandling\Async\QueueWorker;
use Ultra\MessageHandling\EventBusInterface;
use Ultra\MessageHandling\MediatorInterface;

readonly class UltraLightContainer implements ContainerInterface, ResetInterface
{
    public function __construct(
        protected Container $container,
    ) {}

    public function container(): Container
    {
        return $this->container;
    }

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        return $this->container->get($id, $invalidBehavior);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function set(string $id, ?object $service): void
    {
        $this->container->set($id, $service);
    }

    public function initialized(string $id): bool
    {
        return $this->container->initialized($id);
    }

    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
    {
        return $this->container->getParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->container->hasParameter($name);
    }

    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void
    {
        $this->container->setParameter($name, $value);
    }

    public function reset(): void
    {
        $this->container->reset();
    }

    public function mediator(): MediatorInterface
    {
        return $this->get('test.mediator');
    }

    public function eventBus(): EventBusInterface
    {
        return $this->get('test.event_bus');
    }

    public function asyncQueue(): InMemoryQueue
    {
        return $this->get('test.async_queue');
    }

    public function queueWorker(): QueueWorker
    {
        return $this->get('test.queue_worker');
    }
}
