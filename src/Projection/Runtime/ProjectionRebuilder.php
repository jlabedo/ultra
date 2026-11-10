<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\EventStore;

final readonly class ProjectionRebuilder
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentRepositoryInterface $segments,
        private ProjectionStateRepository $states,
        private ProjectionTransactionManagerInterface $transactions,
        private ProjectionRunner $runner,
        private ProjectionBatchExecutor $batches = new ProjectionBatchExecutor(),
    ) {}

    /** Start or resume without requiring event-arrival activity. Does not reset existing progress. */
    public function catchUp(ProjectionRuntime $runtime, string $workerId = 'rebuild', int $batchSize = 100): int
    {
        $this->assertManaged($runtime);
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1.');
        }
        $this->runner->ensureInitialized($runtime);
        $processed = 0;
        foreach ($this->segments->listSegments($runtime->projectionId()) as $segment) {
            do {
                $result = $this->runner->backfillClaimedOnce(
                    $runtime,
                    $runtime->projectionId(),
                    $segment->bucketFrom,
                    $workerId,
                    $batchSize,
                );
                $processed += $result->processedEvents;
            } while ($result->claimedSegment && !$result->caughtUp);
        }

        return $processed;
    }

    /** Briefly exclude writers and workers, drain the tail and activate atomically. */
    public function activate(ProjectionRuntime $runtime, int $batchSize = 100): int
    {
        $this->assertManaged($runtime);
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1.');
        }
        $this->runner->ensureInitialized($runtime);

        return $this->transactions->transactional(function () use ($runtime, $batchSize): int {
            $projectionId = $runtime->projectionId();
            $this->states->lockExclusive($projectionId);
            $state = $this->states->find($projectionId) ?? throw new \LogicException(
                'Projection has not been initialized.',
            );
            if (ProjectionPhase::Live === $state->phase) {
                return 0;
            }
            if (ProjectionState::fingerprint($runtime->definition()) !== $state->fingerprint) {
                throw new \LogicException('Cannot activate an incompatible projection definition.');
            }
            $processed = 0;
            // Under the exclusive barrier no managed batch can still be applying.
            // Take over even unexpired scheduling claims; queued workers recheck phase before loading.
            $segments = $this->segments->takeSegmentsForRange($projectionId, 0, 255, 'activation');
            foreach ($segments as $segment) {
                $checkpoint = $segment->checkpoint;
                do {
                    $result = $this->eventStore->load(
                        $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo),
                        $checkpoint,
                        null,
                        $batchSize,
                    );
                    $this->batches->execute($runtime, $result->events);
                    $processed += count($result->events);
                    $checkpoint = $result->checkpoint;
                } while ($result->hasMore);
                $this->segments->completeClaimedSegment($segment, $checkpoint, 'activation', true);
            }
            $this->states->save(
                new ProjectionState($projectionId, $state->generation, ProjectionPhase::Live, $state->fingerprint),
            );

            return $processed;
        });
    }

    private function assertManaged(ProjectionRuntime $runtime): void
    {
        if (ProjectionMode::Synchronous !== $runtime->definition()->mode) {
            throw new \LogicException('Managed reconstruction requires ProjectionMode::Synchronous.');
        }
    }
}
