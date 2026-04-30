<?php

declare(strict_types=1);

namespace Ultra\Projection\MessageHandling;

final readonly class RunProjectionSegment
{
    public function __construct(
        public string $projectionId,
        public int $bucketFrom,
        public string $workerId,
    ) {}
}
