<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class ColumnDefinition
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public bool $nullable = false,
        public ?string $defaultExpression = null,
    ) {
    }

    public function equivalentTo(self $other): bool
    {
        return $this->type === $other->type
            && $this->nullable === $other->nullable
            && $this->defaultExpression === $other->defaultExpression;
    }
}
