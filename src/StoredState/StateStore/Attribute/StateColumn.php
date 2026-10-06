<?php

declare(strict_types=1);

namespace Ultra\StateStore\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class StateColumn
{
    public function __construct(
        public ?string $name = null,
        public bool $optimistic = true,
    ) {}
}
