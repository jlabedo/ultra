<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\EventStore;

final readonly class ProjectionRunner
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentRepositoryInterface $segmentRepository,
        private ProjectionTransactionManagerInterface $transactionManager = new NoOpProjectionTransactionManager(),
    ) {
    }

    public function recoverExpiredLeases(string $projectionId): int
    {
        return $this->segmentRepository->recoverExpiredLeases($projectionId);
    }

    public function reconcilePendingOperations(string $projectionId): int
    {
        return $this->segmentRepository->reconcilePendingOperations($projectionId);
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
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            $created = $this->segmentRepository->ensureInitialized($definition->projectionId);
            if ($created) {
                $runtime->initialize();
            }
        });

        $segment = $this->segmentRepository->claimSegment($definition->projectionId, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        return $this->processClaimedSegment($runtime, $segment, $limit);
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
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            $created = $this->segmentRepository->ensureInitialized($definition->projectionId);
            if ($created) {
                $runtime->initialize();
            }
        });

        $segment = $this->segmentRepository->claimSegmentForBucket($definition->projectionId, $bucket, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        return $this->processClaimedSegment($runtime, $segment, $limit);
    }

    private function processClaimedSegment(
        ProjectionRuntime $runtime,
        ProjectionSegment $segment,
        int $limit,
    ): ProjectionRunResult {
        try {
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);

            $this->transactionManager->transactional(function () use ($runtime, $segment, $result): void {
                foreach ($result->events as $event) {
                    $runtime->apply($event);
                }

                $runtime->flush();
                $this->segmentRepository->completeSegment($segment, $result->checkpoint);
            });

            return new ProjectionRunResult(
                processedEvents: count($result->events),
                claimedSegment: true,
                segment: $segment,
                caughtUp: count($result->events) < $limit,
            );
        } catch (\Throwable $e) {
            $this->segmentRepository->abandonSegment($segment);

            throw $e;
        }
    }
}
