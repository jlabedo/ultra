<?php

declare(strict_types=1);

namespace Ultra\Mapper_Compiler;

class VariableScope
{
    public function __construct(
        private string $variablePrefix = '$v',
        private int $variableCount = 0,
    ) {}

    public function newVariable(): string
    {
        return $this->variablePrefix . $this->variableCount++;
    }
}
