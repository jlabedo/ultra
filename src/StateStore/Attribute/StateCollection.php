<?php

declare(strict_types=1);

namespace Ultra\StateStore\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class StateCollection
{
    /**
     * @param class-string $itemClass
     */
    public function __construct(
        public string $ownerColumn,
        public string $itemClass,
    ) {}
}
