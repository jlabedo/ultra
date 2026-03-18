<?php

declare(strict_types=1);

namespace Ultra\MessageHandling\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Event
{
    public function __construct(
        public string $name,
    ) {}
}
