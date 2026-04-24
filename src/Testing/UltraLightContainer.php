<?php

declare(strict_types=1);

namespace Ultra\Testing;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

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

    public function getParameter(string $name): array|bool|float|int|string|\UnitEnum|null
    {
        return $this->container->getParameter($name);
    }

    public function hasParameter(string $name): bool
    {
        return $this->container->hasParameter($name);
    }

    public function setParameter(string $name, array|bool|float|int|string|\UnitEnum|null $value): void
    {
        $this->container->setParameter($name, $value);
    }

    public function reset(): void
    {
        $this->container->reset();
    }
}
