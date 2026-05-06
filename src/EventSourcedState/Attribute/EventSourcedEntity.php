<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class EventSourcedEntity
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public array $tags,
    ) {}
}
