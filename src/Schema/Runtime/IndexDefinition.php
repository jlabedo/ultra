<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class IndexDefinition
{
    public string $name;

    public bool $hasExplicitName;

    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $id,
        ?string $name = null,
        public array $columns = [],
        public bool $unique = false,
        public ?string $rawSql = null,
        ?bool $hasExplicitName = null,
    ) {
        $this->name = $name ?? $this->id;
        $this->hasExplicitName = $hasExplicitName ?? null !== $name;
    }

    public function equivalentTo(self $other): bool
    {
        return (
            $this->columns === $other->columns
            && $this->unique === $other->unique
            && $this->rawSql === $other->rawSql
        );
    }
}
