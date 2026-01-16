<?php

declare(strict_types=1);

namespace Ultra\Aop\Tests;

use Psr\Container\ContainerInterface;

/**
 * Simple test container for AOP tests
 */
class TestContainer implements ContainerInterface
{
    private array $services = [];

    public function get(string $id): mixed
    {
        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service '{$id}' not found in container");
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }
}
