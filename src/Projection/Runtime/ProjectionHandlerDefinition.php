<?php

declare(strict_types=1);

namespace Ultra\Projection;

final readonly class ProjectionHandlerDefinition
{
    public function __construct(
        public string $eventType,
        public string $eventClass,
        public string $method,
    ) {}
}
