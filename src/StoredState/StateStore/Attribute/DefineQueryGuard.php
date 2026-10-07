<?php

declare(strict_types=1);

namespace Ultra\StateStore\Attribute;

use Ultra\StateStore\QueryGuardDefinition;

/** Declare query dependencies for constructor injection and automatic participation of mapped writes. */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class DefineQueryGuard
{
    /** @param \Closure(QueryGuardDefinition): void $configure */
    public function __construct(
        public string $name,
        public \Closure $configure,
    ) {}
}
