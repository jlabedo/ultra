<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\EventStore;
use Ultra\Projection\Coordination\ProjectionBucketActivityRepositoryInterface;

final readonly class ProjectionRunner
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentRepositoryInterface $segmentRepository,
        private ProjectionTransactionManagerInterface $transactionManager = new NoOpProjectionTransactionManager(),
        private ?ProjectionBucketActivityRepositoryInterface $activityRepository = null,
    ) {
    }

    public function recoverExpiredAssignments(string $projectionId): int
    {
        return $this->segmentRepository->recoverExpiredAssignments($projectionId);
    }

    public function reconcilePendingOperations(string $projectionId): int
    {
        return $this->segmentRepository->reconcilePendingOperations($projectionId);
    }

    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $this->segmentRepository->splitSegment($projectionId, $bucketFrom);
    }

    /**
     * @return list<array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string}>
     */
    public function listSegments(string $projectionId): array
    {
        return $this->segmentRepository->listSegments($projectionId);
    }

    /**
     * @return list<array{bucketFrom: int, bucketTo: int, status: string, pendingOperation: null|string}>
     */
    public function availableSegments(string $projectionId): array
    {
        return $this->segmentRepository->availableSegments($projectionId);
    }

    public function assignSegment(string $projectionId, int $bucketFrom, string $workerId): ?ProjectionWorkAssignment
    {
        return $this->segmentRepository->assignSegment($projectionId, $bucketFrom, $workerId);
    }

    public function ensureInitialized(ProjectionRuntime $runtime): void
    {
        $definition = $runtime->definition();
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            $created = $this->segmentRepository->ensureInitialized($definition->projectionId);
            if ($created) {
                $runtime->initialize();
            }
        });
    }

    public function runAssignedOnce(
        ProjectionRuntime $runtime,
        ProjectionWorkAssignment $assignment,
        int $limit = 100,
    ): ProjectionRunResult {
        $this->ensureInitialized($runtime);

        $segment = $this->segmentRepository->claimAssignedSegment($assignment);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        try {
            if (null === $this->activityRepository) {
                throw new \LogicException('ProjectionRunner requires a bucket activity repository to run assigned projection work.');
            }

            $seenCounters = [];
            foreach ($this->activityRepository->pendingBucketsForSegment($segment->projectionId, $segment->bucketFrom, $segment->bucketTo) as $activity) {
                $seenCounters[$activity->bucket] = $activity->counter;
            }

            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = count($result->events) < $limit;

            $this->transactionManager->transactional(function () use ($runtime, $segment, $result, $assignment, $caughtUp, $seenCounters): void {
                foreach ($result->events as $event) {
                    $runtime->apply($event);
                }

                $runtime->flush();
                $this->segmentRepository->completeAssignedBatch($segment, $result->checkpoint, $assignment->workerId, $caughtUp);

                if ($caughtUp && [] !== $seenCounters) {
                    $this->activityRepository?->decrementSeenBuckets($segment->projectionId, $seenCounters);
                }
            });

            return new ProjectionRunResult(
                processedEvents: count($result->events),
                claimedSegment: true,
                segment: $segment,
                caughtUp: $caughtUp,
                acknowledgedBuckets: $caughtUp ? count($seenCounters) : 0,
            );
        } catch (\Throwable $e) {
            $this->segmentRepository->abandonAssignedSegment($segment, $assignment->workerId);

            throw $e;
        }
    }
}
