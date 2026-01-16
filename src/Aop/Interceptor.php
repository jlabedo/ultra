<?php

declare(strict_types=1);

namespace Ultra\Aop;

use Psr\Container\ContainerInterface;

class Interceptor
{
    private array $beforeAdvices = [];
    private array $afterAdvices = [];
    private array $afterReturningAdvices = [];
    private array $afterThrowingAdvices = [];
    private array $aroundAdvices = [];

    private ?object $compiled = null;

    public function __construct(
        private readonly CallableReference $callable,
        private readonly ?ContainerInterface $container = null
    ) {
    }

    public function before(CallableReference $advice): self
    {
        $this->beforeAdvices[] = $advice;
        return $this;
    }

    public function after(CallableReference $advice): self
    {
        $this->afterAdvices[] = $advice;
        return $this;
    }

    public function afterReturning(CallableReference $advice): self
    {
        $this->afterReturningAdvices[] = $advice;
        return $this;
    }

    public function afterThrowing(CallableReference $advice): self
    {
        $this->afterThrowingAdvices[] = $advice;
        return $this;
    }

    public function around(CallableReference $advice): self
    {
        $this->aroundAdvices[] = $advice;
        return $this;
    }

    public function call(mixed ...$args): mixed
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is required for runtime execution');
        }

        // Use compiled version if available
        if ($this->compiled !== null) {
            return $this->compiled->call(...$args);
        }

        // Execute before advices
        foreach ($this->beforeAdvices as $advice) {
            $advice->call($this->container);
        }

        // Build the core execution
        $pipeline = function () use ($args) {
            return $this->callable->call($this->container, ...$args);
        };

        // Wrap with around advices (in reverse order)
        foreach (array_reverse($this->aroundAdvices) as $advice) {
            $next = $pipeline;
            $pipeline = function () use ($advice, $next, $args) {
                return $advice->call($this->container, $next, ...$args);
            };
        }

        // Execute with after/afterReturning/afterThrowing handling
        if (!empty($this->afterThrowingAdvices) || !empty($this->afterReturningAdvices) || !empty($this->afterAdvices)) {
            try {
                $returnValue = $pipeline();
                foreach ($this->afterReturningAdvices as $advice) {
                    $advice->call($this->container, $returnValue);
                }
            } catch (\Throwable $e) {
                foreach ($this->afterThrowingAdvices as $advice) {
                    $advice->call($this->container, $e);
                }
                throw $e;
            } finally {
                foreach ($this->afterAdvices as $advice) {
                    $advice->call($this->container);
                }
            }
            return $returnValue;
        } else {
            return $pipeline();
        }
    }

    /**
     * Compile this interceptor to optimized code for minimal runtime overhead
     */
    public function compile(?string $cacheDir = null): self
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container is required for compilation');
        }

        $compiler = new InterceptorCompiler($cacheDir);
        $className = $compiler->compile($this);

        $this->compiled = new $className(
            $this->container
        );

        return $this;
    }

    /**
     * Get a unique hash for this interceptor configuration
     */
    public function getHash(): string
    {
        $metadata = $this->getMetadata();
        return md5(json_encode($metadata));
    }

    /**
     * Get metadata about this interceptor for code generation
     */
    public function getMetadata(): array
    {
        return [
            'callable' => $this->callable->getMetadata(),
            'beforeCount' => count($this->beforeAdvices),
            'afterCount' => count($this->afterAdvices),
            'afterReturningCount' => count($this->afterReturningAdvices),
            'afterThrowingCount' => count($this->afterThrowingAdvices),
            'aroundCount' => count($this->aroundAdvices),
            'beforeAdvices' => array_map(fn($a) => $a->getMetadata(), $this->beforeAdvices),
            'afterAdvices' => array_map(fn($a) => $a->getMetadata(), $this->afterAdvices),
            'afterReturningAdvices' => array_map(fn($a) => $a->getMetadata(), $this->afterReturningAdvices),
            'afterThrowingAdvices' => array_map(fn($a) => $a->getMetadata(), $this->afterThrowingAdvices),
            'aroundAdvices' => array_map(fn($a) => $a->getMetadata(), $this->aroundAdvices),
        ];
    }
}
