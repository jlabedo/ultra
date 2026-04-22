<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\EventStore;

final readonly class ProjectionRunner
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentRepositoryInterface $segmentRepository,
    ) {}

    public function recoverExpiredLeases(string $projectionId): int
    {
        return $this->segmentRepository->recoverExpiredLeases($projectionId);
    }

    public function runOnce(
        ProjectionPlan $plan,
        string $workerId,
        int $limit = 100,
    ): int {
        return $this->runOnceResult($plan, $workerId, $limit)->processedEvents;
    }

    public function runOnceResult(
        ProjectionPlan $plan,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        $created = $this->segmentRepository->ensureInitialized($plan->projectionId());
        if ($created) {
            $plan->initialize();
        }

        $segment = $this->segmentRepository->claimSegment($plan->projectionId(), $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        return $this->processClaimedSegment($plan, $segment, $workerId, $limit);
    }

    public function runBucketOnce(
        ProjectionPlan $plan,
        int $bucket,
        string $workerId,
        int $limit = 100,
    ): int {
        return $this->runBucketOnceResult($plan, $bucket, $workerId, $limit)->processedEvents;
    }

    public function runBucketOnceResult(
        ProjectionPlan $plan,
        int $bucket,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        $created = $this->segmentRepository->ensureInitialized($plan->projectionId());
        if ($created) {
            $plan->initialize();
        }

        $segment = $this->segmentRepository->claimSegmentForBucket($plan->projectionId(), $bucket, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        return $this->processClaimedSegment($plan, $segment, $workerId, $limit);
    }

    private function processClaimedSegment(
        ProjectionPlan $plan,
        ProjectionSegment $segment,
        string $workerId,
        int $limit,
    ): ProjectionRunResult {
        try {
            $query = $plan->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);

            foreach ($result->events as $event) {
                $plan->apply($event);
            }

            $current = $this->segmentRepository->readCurrentState($segment->projectionId, $segment->bucketFrom);

            match ($current->pendingOperation) {
                'split' => $this->segmentRepository->finalizeSplit($current, $result->checkpoint),
                'merge' => $this->segmentRepository->finalizeMerge($current, $result->checkpoint),
                default => $this->segmentRepository->releaseSegment($segment, $result->checkpoint),
            };

            return new ProjectionRunResult(count($result->events), true, $segment);
        } catch (\Throwable $e) {
            $this->segmentRepository->releaseSegment($segment, $segment->checkpoint);
            throw $e;
        }
    }
}
