<?php

declare(strict_types=1);

namespace Ultra\Aop\Tests;

/**
 * Wrapper class to make closures callable via container with a specific method name.
 */
class ClosureCallable
{
    public function __construct(
        private readonly \Closure $closure
    ) {}

    public function invoke(mixed ...$args): mixed
    {
        return ($this->closure)(...$args);
    }
}
