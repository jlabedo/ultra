<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState_Compiler;

final readonly class EventSourcedEntityDefinition
{
    /**
     * @param list<string>                    $tags            identify the entity
     * @param array<string, string>           $aliases         alias name => entity tag, usable in criteria groups
     * @param list<EventSourcedCriteriaGroup> $criteriaGroups
     * @param list<EventSourcedDispatchEntry> $dispatchEntries
     */
    public function __construct(
        public string $entityClass,
        public array $tags,
        public array $aliases,
        public array $criteriaGroups,
        public array $dispatchEntries,
    ) {}
}
