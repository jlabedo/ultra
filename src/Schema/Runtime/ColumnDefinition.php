<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class ColumnDefinition
{
    public string $name;

    public bool $hasExplicitName;

    /**
     * @param list<string> $constraints
     */
    public function __construct(
        public string $id,
        ?string $name = null,
        public string $type = '',
        public bool $nullable = false,
        public ?string $defaultExpression = null,
        public array $constraints = [],
        ?bool $hasExplicitName = null,
    ) {
        if ('' === $type) {
            throw new \InvalidArgumentException(sprintf('Column "%s" must define a type.', $this->id));
        }

        $this->name = $name ?? $this->id;
        $this->hasExplicitName = $hasExplicitName ?? null !== $name;
    }

    public function equivalentTo(self $other): bool
    {
        return (
            $this->type === $other->type
            && $this->nullable === $other->nullable
            && $this->defaultExpression === $other->defaultExpression
            && $this->constraints === $other->constraints
        );
    }
}
