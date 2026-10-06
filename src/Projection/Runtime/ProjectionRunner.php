<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\EventStore;
use Ultra\Projection\Coordination\ProjectionActivity;

final readonly class ProjectionRunner
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentRepositoryInterface $segmentRepository,
        private ProjectionTransactionManagerInterface $transactionManager = new NoOpProjectionTransactionManager(),
        private ?ProjectionActivity $activityRepository = null,
    ) {}

    public function recoverExpiredClaims(string $projectionId): int
    {
        return $this->segmentRepository->recoverExpiredClaims($projectionId);
    }

    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $this->segmentRepository->splitSegment($projectionId, $bucketFrom);
    }

    public function mergeSegments(ProjectionRuntime $runtime, int $bucketFrom, int $bucketTo, string $ownerId, int $limit = 100): void
    {
        $projectionId = $runtime->projectionId();
        $segments = $this->segmentRepository->takeSegmentsForRange($projectionId, $bucketFrom, $bucketTo, $ownerId);
        if ([] === $segments) {
            throw new \LogicException(sprintf(
                'Cannot merge [%d-%d] for projection "%s": no matching segments found.',
                $bucketFrom,
                $bucketTo,
                $projectionId,
            ));
        }

        $targetCheckpoint = $this->mergeCheckpoint(...array_map(static fn (ProjectionSegment $segment): Checkpoint => $segment->checkpoint, $segments));

        foreach ($segments as $segment) {
            $checkpoint = $segment->checkpoint;
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);

            while ($checkpoint->serialize() !== $targetCheckpoint->serialize()) {
                $result = $this->eventStore->load($query, $checkpoint, $targetCheckpoint, $limit);
                $this->transactionManager->transactional(function () use ($runtime, $result): void {
                    foreach ($result->events as $event) {
                        $runtime->apply($event);
                    }

                    $runtime->flush();
                });

                if ($result->checkpoint->serialize() === $checkpoint->serialize()) {
                    throw new \LogicException(sprintf(
                        'Projection merge for "%s" [%d-%d] could not advance segment [%d-%d] to the target checkpoint.',
                        $projectionId,
                        $bucketFrom,
                        $bucketTo,
                        $segment->bucketFrom,
                        $segment->bucketTo,
                    ));
                }

                $checkpoint = $result->checkpoint;
            }
        }

        $this->segmentRepository->replaceSegmentsWithMergedSegment($projectionId, $bucketFrom, $bucketTo, $targetCheckpoint);
    }

    /** @return list<ProjectionSegment> */
    public function listSegments(string $projectionId): array
    {
        return $this->segmentRepository->listSegments($projectionId);
    }

    /** @return list<ProjectionSegment> */
    public function availableSegments(string $projectionId): array
    {
        return $this->segmentRepository->availableSegments($projectionId);
    }

    public function claimSegment(string $projectionId, int $bucketFrom, string $workerId): ?ProjectionSegment
    {
        return $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
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

    public function deleteProjection(ProjectionRuntime $runtime): void
    {
        $definition = $runtime->definition();
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            $runtime->reset();
            $this->segmentRepository->deleteProjection($definition->projectionId);
        });
    }

    public function runClaimedOnce(
        ProjectionRuntime $runtime,
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        $this->ensureInitialized($runtime);

        $segment = $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        try {
            if (null === $this->activityRepository) {
                throw new \LogicException('ProjectionRunner requires projection activity to run claimed projection work.');
            }

            $seenCounters = [];
            foreach ($this->activityRepository->pendingInSegment($segment->projectionId, $segment->bucketFrom, $segment->bucketTo) as $pendingKey) {
                $seenCounters[(int) $pendingKey->key] = $pendingKey->counter;
            }

            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = !$result->hasMore;

            $this->transactionManager->transactional(function () use ($runtime, $segment, $result, $workerId, $caughtUp, $seenCounters): void {
                foreach ($result->events as $event) {
                    $runtime->apply($event);
                }

                $runtime->flush();
                $this->segmentRepository->completeClaimedSegment($segment, $result->checkpoint, $workerId, $caughtUp);

                if ($caughtUp && [] !== $seenCounters) {
                    $this->activityRepository?->drainSeen($segment->projectionId, $seenCounters);
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
            $this->segmentRepository->releaseClaimedSegment($segment, $workerId);

            throw $e;
        }
    }

    /**
     * Replay a projection from event 0: drop its state and checkpoints, then
     * stream every event from the event store back through its handlers until
     * all segments are caught up.
     *
     * Intended for tests and one-shot offline rebuilds. Uses the same
     * transactional, segment-claimed backfill path as `backfillClaimedOnce`
     * — no bucket-activity coordination, no event-arrival semantics. The
     * caller owns the event store; this method does not wait for new events.
     *
     * Returns the total number of events processed.
     */
    public function replayFromBeginning(
        ProjectionRuntime $runtime,
        string $workerId = 'replay',
        int $limit = 100,
    ): int {
        $projectionId = $runtime->projectionId();

        $this->deleteProjection($runtime);
        $this->ensureInitialized($runtime);

        $totalEvents = 0;
        while (true) {
            $progressed = false;
            foreach ($this->listSegments($projectionId) as $segment) {
                // Loop this segment until caught up. `backfillClaimedOnce`
                // claims with `$workerId`; the same worker can re-claim its
                // own in-progress lease, so successive calls advance the
                // checkpoint batch by batch until `caughtUp` is true.
                while (true) {
                    $result = $this->backfillClaimedOnce(
                        $runtime,
                        $projectionId,
                        $segment->bucketFrom,
                        $workerId,
                        $limit,
                    );
                    if (!$result->claimedSegment) {
                        // Held by a different worker; skip this round.
                        break;
                    }
                    $totalEvents += $result->processedEvents;
                    if ($result->processedEvents > 0) {
                        $progressed = true;
                    }
                    if ($result->caughtUp) {
                        break;
                    }
                }
            }
            if (!$progressed) {
                break;
            }
        }

        return $totalEvents;
    }

    public function backfillClaimedOnce(
        ProjectionRuntime $runtime,
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        $this->ensureInitialized($runtime);

        $segment = $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        try {
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = !$result->hasMore;

            $this->transactionManager->transactional(function () use ($runtime, $segment, $result, $workerId, $caughtUp): void {
                foreach ($result->events as $event) {
                    $runtime->apply($event);
                }

                $runtime->flush();
                $this->segmentRepository->completeClaimedSegment($segment, $result->checkpoint, $workerId, $caughtUp);
            });

            return new ProjectionRunResult(
                processedEvents: count($result->events),
                claimedSegment: true,
                segment: $segment,
                caughtUp: $caughtUp,
            );
        } catch (\Throwable $e) {
            $this->segmentRepository->releaseClaimedSegment($segment, $workerId);

            throw $e;
        }
    }

    private function mergeCheckpoint(Checkpoint ...$checkpoints): Checkpoint
    {
        $highest = $checkpoints[0] ?? Checkpoint::beginning();

        foreach ($checkpoints as $checkpoint) {
            $comparison = $highest->gapFloor->compareTo($checkpoint->gapFloor);
            if ($comparison < 0) {
                $highest = $checkpoint;

                continue;
            }

            if (0 === $comparison) {
                $highest = new Checkpoint($highest->gapFloor, [...$highest->gapSeqNos, ...$checkpoint->gapSeqNos]);
            }
        }

        return $highest;
    }
}
