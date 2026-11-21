<?php

declare(strict_types=1);

namespace Ultra\MappedTable\Attribute;

use Ultra\MappedTable\QueryGuardDependency;

/** Declares and injects a named guard from validated mapped row-set dependencies. */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class DefineQueryGuard
{
    /** @param list<QueryGuardDependency> $dependencies */
    public function __construct(
        public string $name,
        public array $dependencies,
    ) {}
}
