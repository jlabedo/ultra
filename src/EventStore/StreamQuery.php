<?php

declare(strict_types=1);

namespace Ultra\EventStore;

readonly class StreamQuery
{
    /**
     * @param array<string, string>   $domainIdentifiers dimension => value (event must match all when orCriteria is empty)
     * @param array<string>           $eventNames       event types to include (empty = all)
     * @param list<array<string,string>> $orCriteria    when non-empty: event matches if it matches any criterion (each criterion is dimension => value)
     */
    public function __construct(
        public array $domainIdentifiers = [],
        public array $eventNames = [],
        public array $orCriteria = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->domainIdentifiers === [] && $this->orCriteria === [];
    }
}
