<?php

declare(strict_types=1);

namespace Ultra\DecisionEngine_Compiler;

final readonly class EventSourcedEntityDefinition
{
    /**
     * @param list<string>                    $tags
     * @param list<EventSourcedCriteriaGroup> $criteriaGroups
     * @param list<EventSourcedDispatchEntry> $dispatchEntries
     */
    public function __construct(
        public string $entityClass,
        public array $tags,
        public array $criteriaGroups,
        public array $dispatchEntries,
    ) {}
}
