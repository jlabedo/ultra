<?php

declare(strict_types=1);

namespace Ultra\Projection\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class ProjectionHandler
{
    public function __construct(
        public string $eventType,
    ) {
        if ('' === $this->eventType) {
            throw new \InvalidArgumentException('Projection handler event types must be non-empty strings.');
        }
    }
}
