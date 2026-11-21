<?php

declare(strict_types=1);

namespace Ultra\MappedTable;

final readonly class QueryGuardDefinition
{
    /** @param list<QueryGuardDependency> $dependencies */
    public function __construct(
        public string $name,
        public array $dependencies,
    ) {
        if (array_any(
            $dependencies,
            static fn(mixed $dependency): bool => !$dependency instanceof QueryGuardDependency,
        )) {
            throw new \InvalidArgumentException('A query guard dependency must be a QueryGuardDependency.');
        }
    }
}
