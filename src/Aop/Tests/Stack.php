<?php

declare(strict_types=1);

class Stack
{
    public function __construct(private(set) array $stack = []) {}

    public function push(mixed $value): void
    {
        $this->stack[] = $value;
    }

    public function reset(): void
    {
        $this->stack = [];
    }
}
