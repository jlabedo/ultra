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
        ProjectionRuntime $runtime,
        string $workerId,
        int $limit = 100,
    ): int {
        return $this->runOnceResult($runtime, $workerId, $limit)->processedEvents;
    }

    public function runOnceResult(
        ProjectionRuntime $runtime,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        $definition = $runtime->definition();
        $created = $this->segmentRepository->ensureInitialized($definition->projectionId);
        if ($created) {
            $runtime->initialize();
        }

        $segment = $this->segmentRepository->claimSegment($definition->projectionId, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        return $this->processClaimedSegment($runtime, $segment, $workerId, $limit);
    }

    public function runBucketOnce(
        ProjectionRuntime $runtime,
        int $bucket,
        string $workerId,
        int $limit = 100,
    ): int {
        return $this->runBucketOnceResult($runtime, $bucket, $workerId, $limit)->processedEvents;
    }

    public function runBucketOnceResult(
        ProjectionRuntime $runtime,
        int $bucket,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        $definition = $runtime->definition();
        $created = $this->segmentRepository->ensureInitialized($definition->projectionId);
        if ($created) {
            $runtime->initialize();
        }

        $segment = $this->segmentRepository->claimSegmentForBucket($definition->projectionId, $bucket, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        return $this->processClaimedSegment($runtime, $segment, $workerId, $limit);
    }

    private function processClaimedSegment(
        ProjectionRuntime $runtime,
        ProjectionSegment $segment,
        string $workerId,
        int $limit,
    ): ProjectionRunResult {
        try {
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);

            foreach ($result->events as $event) {
                $runtime->apply($event);
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
