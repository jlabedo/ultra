<?php

declare(strict_types=1);

namespace Ultra\Aop;

class Interceptor
{
    private array $beforeAdvices = [];
    private array $afterAdvices = [];
    private array $afterReturningAdvices = [];
    private array $afterThrowingAdvices = [];
    private array $aroundAdvices = [];

    private ?object $compiled = null;

    public function __construct(
        private readonly mixed $callable
    ) {
    }

    public function before(callable $advice): self
    {
        $this->beforeAdvices[] = $advice;
        return $this;
    }

    public function after(callable $advice): self
    {
        $this->afterAdvices[] = $advice;
        return $this;
    }

    public function afterReturning(callable $advice): self
    {
        $this->afterReturningAdvices[] = $advice;
        return $this;
    }

    public function afterThrowing(callable $advice): self
    {
        $this->afterThrowingAdvices[] = $advice;
        return $this;
    }

    public function around(callable $advice): self
    {
        $this->aroundAdvices[] = $advice;
        return $this;
    }

    public function call(mixed ...$args): mixed
    {
        // Use compiled version if available
        if ($this->compiled !== null) {
            return $this->compiled->call(...$args);
        }

        // Execute before advices
        foreach ($this->beforeAdvices as $advice) {
            $advice();
        }

        // Build the core execution
        $pipeline = function () use ($args) {
            return ($this->callable)(...$args);
        };

        // Wrap with around advices (in reverse order)
        foreach (array_reverse($this->aroundAdvices) as $advice) {
            $next = $pipeline;
            $pipeline = function () use ($advice, $next, $args) {
                return $advice($next, ...$args);
            };
        }

        // Execute with after/afterReturning/afterThrowing handling
        if (!empty($this->afterThrowingAdvices) || !empty($this->afterReturningAdvices) || !empty($this->afterAdvices)) {
            try {
                $returnValue = $pipeline();
                foreach ($this->afterReturningAdvices as $advice) {
                    $advice($returnValue);
                }
            } catch (\Throwable $e) {
                foreach ($this->afterThrowingAdvices as $advice) {
                    $advice($e);
                }
                throw $e;
            } finally {
                foreach ($this->afterAdvices as $advice) {
                    $advice();
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
        $compiler = new InterceptorCompiler($cacheDir);
        $className = $compiler->compile($this);

        $this->compiled = new $className(
            $this->callable,
            $this->beforeAdvices,
            $this->afterAdvices,
            $this->afterReturningAdvices,
            $this->afterThrowingAdvices,
            $this->aroundAdvices
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
            'beforeCount' => count($this->beforeAdvices),
            'afterCount' => count($this->afterAdvices),
            'afterReturningCount' => count($this->afterReturningAdvices),
            'afterThrowingCount' => count($this->afterThrowingAdvices),
            'aroundCount' => count($this->aroundAdvices),
        ];
    }
}
