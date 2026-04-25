<?php

declare(strict_types=1);

namespace Ultra\Schema;

final readonly class IndexDefinition
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $columns,
        public bool $unique = false,
        public ?string $rawSql = null,
    ) {
    }

    public function equivalentTo(self $other): bool
    {
        return $this->columns === $other->columns
            && $this->unique === $other->unique
            && $this->rawSql === $other->rawSql;
    }
}
