<?php

declare(strict_types=1);

namespace Ultra\Aop;

use Ultra\Aop\Attribute\Advice;
use Ultra\Aop\Attribute\After;
use Ultra\Aop\Attribute\AfterReturning;
use Ultra\Aop\Attribute\AfterThrowing;
use Ultra\Aop\Attribute\Around;
use Ultra\Aop\Attribute\Before;

class InterceptorBuilder
{
    /** @var Advice[] */
    public array $advices = [];
    public function __construct(public object $interceptor, public string $methodName)
    {
    }

    public function before(string $class, string $method): self
    {
        $this->advices[] = new Before("$class::$method");
        return $this;
    }

    public function after(string $class, string $method): self
    {
        $this->advices[] = new After("$class::$method");
        return $this;
    }

    public function afterReturning(string $class, string $method): self
    {
        $this->advices[] = new AfterReturning("$class::$method");
        return $this;
    }

    public function afterThrowing(string $class, string $method): self
    {
        $this->advices[] = new AfterThrowing("$class::$method");
        return $this;
    }

    public function around(string $class, string $method): self
    {
        $this->advices[] = new Around("$class::$method");
        return $this;
    }
}