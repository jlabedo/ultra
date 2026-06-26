<?php

declare(strict_types=1);

namespace Ultra\Projection\Backfill;

final readonly class ProjectionBackfillProcessResult
{
    public function __construct(
        public ProjectionBackfillJob $job,
        public int $exitCode,
        public string $output,
        public string $errorOutput,
    ) {}
}

