<?php

declare(strict_types=1);

namespace Ultra\Mapper_Example\Domain;

final class RuntimeEmail
{
    public function __construct(private string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }
}
