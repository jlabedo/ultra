<?php

declare(strict_types=1);

namespace Ultra\Aop\Attribute;

abstract class Advice
{
    public function __construct(public readonly string $pointcut) {}
}
