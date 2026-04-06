<?php

declare(strict_types=1);

namespace Ultra\EventStore;

/**
 * @internal
 */
final readonly class BufferedAppend
{
    /**
     * @param list<Event> $events
     */
    public function __construct(
        public array $events,
        public ?QueryVersion $expectedVersion,
    ) {}
}
