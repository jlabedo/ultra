<?php

declare(strict_types=1);

namespace Ultra\Projection;

final readonly class ProjectionRunResult
{
    public function __construct(
        public int $processedEvents,
        public bool $claimedSegment,
        public ?ProjectionSegment $segment,
    ) {}
}
