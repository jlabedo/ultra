<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class QueryGuardDefinition
{
    /** @param list<QueryGuardDependency> $dependencies */
    public function __construct(
        public string $name,
        public array $dependencies,
    ) {}
}
