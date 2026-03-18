<?php

declare(strict_types=1);

namespace Ultra\EventBus\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class EventHandler
{
    public function __construct(
        public int $priority = 0,
    ) {}
}
