<?php

declare(strict_types=1);

namespace Ultra\Projection\Backfill;

interface ProjectionBackfillExecutorInterface
{
    /**
     * @param list<ProjectionBackfillJob> $jobs
     *
     * @return list<ProjectionBackfillProcessResult>
     */
    public function run(array $jobs): array;
}
