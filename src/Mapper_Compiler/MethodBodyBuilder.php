<?php

namespace Ultra\Mapper_Compiler;

class MethodBodyBuilder
{
    /**
     * @var string[]
     */
    private array $statements = [];

    private ?string $returnExpr = null;

    public function __construct(
        private VariableScope $scope = new VariableScope(),
    ) {}

    /**
     * Add a statement to the method body (without a trailing semicolon).
     */
    public function addStatement(string $code): void
    {
        $this->statements[] = rtrim($code, '; ').';';
    }

    public function newVariable(): string
    {
        return $this->scope->newVariable();
    }

    /**
     * Add a blank line for readability.
     */
    public function addBlankLine(): void
    {
        $this->statements[] = '';
    }

    /**
     * Add a comment line.
     */
    public function addComment(string $comment): void
    {
        $this->statements[] = '// '.$comment;
    }

    /**
     * Set the return expression for the method (without the 'return' keyword).
     */
    public function setReturn(string $expr): void
    {
        $this->returnExpr = $expr;
    }

    /**
     * Get the full method body as a string, including the return statement if set.
     */
    public function getCode(): string
    {
        $lines = $this->statements;
        if (null !== $this->returnExpr) {
            if (!empty($lines) && '' !== end($lines)) {
                $lines[] = '';
            }
            $lines[] = 'return '.rtrim($this->returnExpr, '; ').';';
        }

        return implode("\n", $lines);
    }

    public function newScope(): self
    {
        return new self($this->scope);
    }
}
