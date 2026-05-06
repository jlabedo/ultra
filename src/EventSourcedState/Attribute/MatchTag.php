<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class MatchTag
{
    /**
     * @param string ...$tags
     */
    public array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = $tags;
    }
}
