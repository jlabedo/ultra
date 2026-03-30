<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine_Compiler;

final readonly class EventSourcedCriteriaGroup
{
    /**
     * @param list<string> $tags
     * @param list<string> $eventNames
     */
    public function __construct(
        public array $tags,
        public array $eventNames,
    ) {}
}
