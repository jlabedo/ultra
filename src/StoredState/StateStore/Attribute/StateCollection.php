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
        public string $itemClass,
        /** @var array<string, string> */
        public array $ownerColumns = [],
    ) {}
}
