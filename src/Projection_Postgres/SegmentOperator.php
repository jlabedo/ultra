<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

/**
 * Operator-facing service for managing projection segment topology.
 * Wrap this in a CLI command or admin endpoint to trigger split/merge.
 */
final readonly class SegmentOperator
{
    public function __construct(
        private PostgresProjectionSegmentRepository $repository,
    ) {}

    /**
     * Splits the segment starting at $bucketFrom into two equal halves.
     *
     * If the segment is idle (available), the split is executed immediately and returns
     * a description of the two new segments.
     *
     * If the segment is active (claimed by a worker), it is marked for draining. The
     * worker will execute the split atomically on its next iteration and exit. New
     * workers can then claim the two resulting segments.
     *
     * @throws \InvalidArgumentException if the segment does not exist
     * @throws \LogicException           if the segment is already at minimum granularity (1 bucket)
     */
    public function split(string $projectionId, int $bucketFrom): string
    {
        $this->repository->splitSegment($projectionId, $bucketFrom);

        $rangeSize = $this->resolveRangeSize($projectionId, $bucketFrom);
        $mid = $bucketFrom + intdiv($rangeSize, 2);

        if ($rangeSize > 0) {
            return sprintf(
                'Segment [%d-%d] marked for split into [%d-%d] and [%d-%d]. Workers will finalize on next iteration.',
                $bucketFrom, $bucketFrom + $rangeSize - 1,
                $bucketFrom, $mid - 1,
                $mid, $bucketFrom + $rangeSize - 1,
            );
        }

        return sprintf('Segment [%d] split requested.', $bucketFrom);
    }

    /**
     * Requests a merge of the two sibling segments that together cover [$bucketFrom, $bucketTo].
     *
     * Both siblings are marked for draining. Each worker finishes its current batch and exits.
     * The last worker to finish creates the parent segment at min(checkpoint_A, checkpoint_B).
     *
     * Preconditions:
     * - Both siblings [bucketFrom, mid-1] and [mid, bucketTo] must exist and be available or active.
     * - The parent range [bucketFrom, bucketTo] must not already exist.
     *
     * @throws \LogicException if the preconditions are not met
     */
    public function merge(string $projectionId, int $bucketFrom, int $bucketTo): string
    {
        $this->repository->requestMerge($projectionId, $bucketFrom, $bucketTo);

        $rangeSize = $bucketTo - $bucketFrom + 1;
        $mid = $bucketFrom + intdiv($rangeSize, 2);

        return sprintf(
            'Segments [%d-%d] and [%d-%d] marked for merge into [%d-%d]. Workers will finalize on next iteration.',
            $bucketFrom, $mid - 1,
            $mid, $bucketTo,
            $bucketFrom, $bucketTo,
        );
    }

    /**
     * Returns the current segment topology for a projection (for monitoring/debugging).
     *
     * @return list<array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: string|null}>
     */
    public function listSegments(string $projectionId): array
    {
        return $this->repository->listSegments($projectionId);
    }

    private function resolveRangeSize(string $projectionId, int $bucketFrom): int
    {
        try {
            $state = $this->repository->readCurrentState($projectionId, $bucketFrom);
            return $state->bucketTo - $state->bucketFrom + 1;
        } catch (\Throwable) {
            return 0;
        }
    }
}
