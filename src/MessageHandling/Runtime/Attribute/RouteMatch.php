<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

final readonly class RouteMatch
{
    private function __construct(
        public int $priority = 0,
    ) {}

    public static function for(int $priority = 0): self
    {
        return new self(priority: $priority);
    }
}
