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
}
