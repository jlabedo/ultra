<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class StreamQuery
{
    /**
     * @param array<string, string> $domainIdentifiers dimension => value (event must match all)
     * @param array<string>         $eventNames       event types to include (empty = all)
     */
    public function __construct(
        public array $domainIdentifiers,
        public array $eventNames = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->domainIdentifiers === [];
    }
}
