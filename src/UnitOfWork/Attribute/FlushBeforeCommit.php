<?php

declare(strict_types=1);

namespace Ultra\UnitOfWork\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class FlushBeforeCommit
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
