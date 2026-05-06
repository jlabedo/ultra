<?php

declare(strict_types=1);

namespace Ultra\EventSourcedState_Compiler;

final readonly class EventSourcedDispatchEntry
{
    public function __construct(
        public string $eventClass,
        public string $eventName,
        public string $method,
    ) {}
}
