<?php

declare(strict_types=1);

namespace Ultra\StateStore\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class StateEmbedded
{
    public function __construct(
        public string $prefix,
        public bool $optimistic = true,
    ) {}
}
