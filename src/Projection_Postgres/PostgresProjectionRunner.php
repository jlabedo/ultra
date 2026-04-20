<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Ultra\EventStore\EventStore;
use Ultra\Projection\ProjectionPlan;

final readonly class PostgresProjectionRunner
{
    public function __construct(
        private EventStore $eventStore,
        private PostgresProjectionSegmentRepository $segmentRepository,
    ) {}

    /**
     * Claims a segment, processes up to $limit events, and releases the segment.
     *
     * If a split or merge was requested while processing, the worker finalizes
     * the operation atomically before exiting.
     *
     * Returns the number of events processed, or 0 if no segment was available.
     */
    public function runOnce(
        ProjectionPlan $plan,
        string $workerId,
        int $limit = 100,
    ): int {
        $created = $this->segmentRepository->ensureInitialized($plan->projectionId());
        if ($created) {
            $plan->initialize();
        }

        $segment = $this->segmentRepository->claimSegment($plan->projectionId(), $workerId);
        if (null === $segment) {
            return 0;
        }

        try {
            $query = $plan->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);

            foreach ($result->events as $event) {
                $plan->apply($event);
            }

            // Re-read current status to detect draining requests that arrived during processing
            $current = $this->segmentRepository->readCurrentState($segment->projectionId, $segment->bucketFrom);

            match ($current->pendingOperation) {
                'split' => $this->segmentRepository->finalizeSplit($current, $result->checkpoint),
                'merge' => $this->segmentRepository->finalizeMerge($current, $result->checkpoint),
                default => $this->segmentRepository->releaseSegment($segment, $result->checkpoint),
            };

            return count($result->events);
        } catch (\Throwable $e) {
            $this->segmentRepository->releaseSegment($segment, $segment->checkpoint);
            throw $e;
        }
    }
}
