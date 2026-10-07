<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class EventSourcedEntity
{
    /**
     * @param list<string>          $tags    identify the entity: one value per tag when loading it
     * @param array<array-key, string> $aliases alias name => entity tag; events are also loaded under the alias name,
     *                                       with the value of the aliased tag
     */
    public function __construct(
        public array $tags,
        public array $aliases = [],
    ) {}
}
