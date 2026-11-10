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
        private ProjectionStateRepository $states = new \Ultra\Projection\InMemory\InMemoryProjectionStateRepository(),
        private ProjectionBatchExecutor $batches = new ProjectionBatchExecutor(),
    ) {}

    public function recoverExpiredClaims(string $projectionId): int
    {
        return $this->segmentRepository->recoverExpiredClaims($projectionId);
    }

    public function splitSegment(string $projectionId, int $bucketFrom): void
    {
        $this->transactionManager->transactional(function () use ($projectionId, $bucketFrom): void {
            $this->states->lockExclusive($projectionId);
            if (ProjectionPhase::Live === $this->states->find($projectionId)?->phase) {
                throw new \LogicException('Cannot split a live synchronous projection.');
            }
            $this->segmentRepository->splitSegment($projectionId, $bucketFrom);
        });
    }

    public function mergeSegments(
        ProjectionRuntime $runtime,
        int $bucketFrom,
        int $bucketTo,
        string $ownerId,
        int $limit = 100,
    ): void {
        $this->transactionManager->transactional(function () use (
            $runtime,
            $bucketFrom,
            $bucketTo,
            $ownerId,
            $limit,
        ): void {
            $this->states->lockRegistrationShared();
            $this->states->lockExclusive($runtime->projectionId());
            $this->assertWorkerDefinition($runtime);
            if (!$this->isReplayable($runtime)) {
                throw new \LogicException('Cannot merge a live synchronous projection.');
            }
            $this->mergeSegmentsUnderBarrier($runtime, $bucketFrom, $bucketTo, $ownerId, $limit);
        });
    }

    private function mergeSegmentsUnderBarrier(
        ProjectionRuntime $runtime,
        int $bucketFrom,
        int $bucketTo,
        string $ownerId,
        int $limit,
    ): void {
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

        $targetCheckpoint = $this->mergeCheckpoint(...array_map(
            static fn(ProjectionSegment $segment): Checkpoint => $segment->checkpoint,
            $segments,
        ));

        foreach ($segments as $segment) {
            $checkpoint = $segment->checkpoint;
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);

            while ($checkpoint->serialize() !== $targetCheckpoint->serialize()) {
                $result = $this->eventStore->load($query, $checkpoint, $targetCheckpoint, $limit);
                $this->transactionManager->transactional(function () use ($runtime, $result): void {
                    $this->assertWorkerDefinition($runtime);
                    $this->batches->execute($runtime, $result->events);
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

        $this->segmentRepository->replaceSegmentsWithMergedSegment(
            $projectionId,
            $bucketFrom,
            $bucketTo,
            $targetCheckpoint,
        );
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
        if (null === $this->states->find($projectionId)) {
            return $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
        }

        return $this->transactionManager->transactional(function () use (
            $projectionId,
            $bucketFrom,
            $workerId,
        ): ?ProjectionSegment {
            $this->states->lockShared($projectionId);
            if (ProjectionPhase::Live === $this->states->find($projectionId)?->phase) {
                return null;
            }

            return $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
        });
    }

    public function ensureInitialized(ProjectionRuntime $runtime): void
    {
        $definition = $runtime->definition();
        if (ProjectionMode::SynchronousFromNow === $definition->mode) {
            throw new \LogicException(
                'SynchronousFromNow projections use application-managed initialization and have no replay state.',
            );
        }
        $existing = $this->states->find($definition->projectionId);
        if (null !== $existing) {
            $this->assertCompatibleState($runtime, $existing);

            return;
        }
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            if (ProjectionMode::Synchronous === $definition->mode) {
                $this->states->lockRegistrationExclusive();
                $this->states->lockExclusive($definition->projectionId);
                $state = $this->states->find($definition->projectionId);
                if (null !== $state) {
                    $this->assertCompatibleState($runtime, $state);

                    return;
                }
                $this->states->save(
                    new ProjectionState(
                        $definition->projectionId,
                        1,
                        ProjectionPhase::Building,
                        ProjectionState::fingerprint($definition),
                    ),
                );
            }
            if (ProjectionMode::Asynchronous === $definition->mode) {
                $this->assertWorkerDefinition($runtime);
            }
            $created = $this->segmentRepository->ensureInitialized($definition->projectionId);
            if ($created) {
                $this->batches->lifecycle($runtime, $runtime->initialize(...));
            }
        });
    }

    public function deleteProjection(ProjectionRuntime $runtime): void
    {
        $definition = $runtime->definition();
        if (ProjectionMode::SynchronousFromNow === $definition->mode) {
            throw new \LogicException('SynchronousFromNow projections cannot be reset through the replay runner.');
        }
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            if (ProjectionMode::Asynchronous === $definition->mode) {
                $this->assertWorkerDefinition($runtime);
            }
            if (ProjectionMode::Synchronous === $definition->mode) {
                if (null === $this->states->find($definition->projectionId)) {
                    $this->states->lockRegistrationExclusive();
                } else {
                    $this->states->lockRegistrationShared();
                }
                $this->states->lockExclusive($definition->projectionId);
                $previous = $this->states->find($definition->projectionId);
                $this->states->save(
                    new ProjectionState(
                        $definition->projectionId,
                        ($previous->generation ?? 0) + 1,
                        ProjectionPhase::Building,
                        ProjectionState::fingerprint($definition),
                    ),
                );
            }
            $this->batches->lifecycle($runtime, $runtime->reset(...));
            $this->segmentRepository->deleteProjection($definition->projectionId);
            if (ProjectionMode::Synchronous === $definition->mode) {
                $this->segmentRepository->ensureInitialized($definition->projectionId);
                $this->batches->lifecycle($runtime, $runtime->initialize(...));
            }
        });
    }

    public function isReplayable(ProjectionRuntime $runtime): bool
    {
        return (
            ProjectionMode::SynchronousFromNow !== $runtime->definition()->mode
            && (
                ProjectionMode::Asynchronous === $runtime->definition()->mode
                || ProjectionPhase::Live !== $this->states->find($runtime->projectionId())?->phase
            )
        );
    }

    private function assertWorkerDefinition(ProjectionRuntime $runtime): void
    {
        $this->states->lockRegistrationShared();
        $state = $this->states->find($runtime->projectionId());
        if (null !== $state) {
            $this->assertCompatibleState($runtime, $state);
            if (ProjectionPhase::Live === $state->phase) {
                throw new \LogicException('A live synchronous projection cannot be replayed by a worker.');
            }
        }
    }

    private function assertCompatibleState(ProjectionRuntime $runtime, ProjectionState $state): void
    {
        if (
            ProjectionMode::Synchronous !== $runtime->definition()->mode
            || ProjectionState::fingerprint($runtime->definition()) !== $state->fingerprint
        ) {
            throw new \LogicException('Projection definition changed; explicitly reset the projection before replay.');
        }
    }

    public function runClaimedOnce(
        ProjectionRuntime $runtime,
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        int $limit = 100,
    ): ProjectionRunResult {
        if ($projectionId !== $runtime->projectionId()) {
            throw new \InvalidArgumentException('Runtime and claimed projection must match.');
        }
        $this->ensureInitialized($runtime);
        if (ProjectionMode::Asynchronous === $runtime->definition()->mode) {
            return $this->runClaimedOnceBatch($runtime, $projectionId, $bucketFrom, $workerId, $limit);
        }

        return $this->transactionManager->transactional(function () use (
            $runtime,
            $projectionId,
            $bucketFrom,
            $workerId,
            $limit,
        ): ProjectionRunResult {
            $this->states->lockRegistrationShared();
            $this->states->lockShared($projectionId);
            $state = $this->states->find($projectionId);
            if (null === $state || ProjectionPhase::Live === $state->phase) {
                return new ProjectionRunResult(0, false, null);
            }
            $this->assertCompatibleState($runtime, $state);

            return $this->runClaimedOnceBatch($runtime, $projectionId, $bucketFrom, $workerId, $limit);
        });
    }

    private function runClaimedOnceBatch(
        ProjectionRuntime $runtime,
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        int $limit,
    ): ProjectionRunResult {
        $segment = $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        try {
            if (null === $this->activityRepository) {
                throw new \LogicException(
                    'ProjectionRunner requires projection activity to run claimed projection work.',
                );
            }

            $seenCounters = [];
            foreach ($this->activityRepository->pendingInSegment(
                $segment->projectionId,
                $segment->bucketFrom,
                $segment->bucketTo,
            ) as $pendingKey) {
                $seenCounters[(int) $pendingKey->key] = $pendingKey->counter;
            }

            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = !$result->hasMore;

            $this->transactionManager->transactional(function () use (
                $runtime,
                $segment,
                $result,
                $workerId,
                $caughtUp,
                $seenCounters,
            ): void {
                $this->assertWorkerDefinition($runtime);
                $this->batches->execute($runtime, $result->events);
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
    public function replayFromBeginning(ProjectionRuntime $runtime, string $workerId = 'replay', int $limit = 100): int
    {
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
        if ($projectionId !== $runtime->projectionId()) {
            throw new \InvalidArgumentException('Runtime and claimed projection must match.');
        }
        $this->ensureInitialized($runtime);
        if (ProjectionMode::Asynchronous === $runtime->definition()->mode) {
            return $this->backfillClaimedOnceBatch($runtime, $projectionId, $bucketFrom, $workerId, $limit);
        }

        return $this->transactionManager->transactional(function () use (
            $runtime,
            $projectionId,
            $bucketFrom,
            $workerId,
            $limit,
        ): ProjectionRunResult {
            $this->states->lockRegistrationShared();
            $this->states->lockShared($projectionId);
            $state = $this->states->find($projectionId);
            if (null === $state || ProjectionPhase::Live === $state->phase) {
                return new ProjectionRunResult(0, false, null);
            }
            $this->assertCompatibleState($runtime, $state);

            return $this->backfillClaimedOnceBatch($runtime, $projectionId, $bucketFrom, $workerId, $limit);
        });
    }

    private function backfillClaimedOnceBatch(
        ProjectionRuntime $runtime,
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        int $limit,
    ): ProjectionRunResult {
        $segment = $this->segmentRepository->claimSegment($projectionId, $bucketFrom, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        try {
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = !$result->hasMore;

            $this->transactionManager->transactional(function () use (
                $runtime,
                $segment,
                $result,
                $workerId,
                $caughtUp,
            ): void {
                $this->assertWorkerDefinition($runtime);
                $this->batches->execute($runtime, $result->events);
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
