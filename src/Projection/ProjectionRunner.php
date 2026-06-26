<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\EventStore;
use Ultra\Signal\BucketRange;
use Ultra\Signal\SignalStore;
use Ultra\Signal\SuppressionHandle;
use Ultra\Time\DatePoint;
use Ultra\Time\Duration;

final readonly class ProjectionRunner
{
    public function __construct(
        private EventStore $eventStore,
        private ProjectionSegmentStoreInterface $segmentStore,
        private ProjectionTransactionManagerInterface $transactionManager = new NoOpProjectionTransactionManager(),
    ) {}

    public function recoverExpiredClaims(string $projectionId): int
    {
        return $this->segmentStore->recoverExpiredClaims($projectionId);
    }

    public function ensureInitialized(ProjectionRuntime $runtime): void
    {
        $definition = $runtime->definition();
        $this->transactionManager->transactional(function () use ($definition, $runtime): void {
            $created = $this->segmentStore->ensureInitialized($definition->projectionId);
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
            $this->segmentStore->deleteProjection($definition->projectionId);
        });
    }

    public function runSignaledOnce(
        ProjectionRuntime $runtime,
        SignalStore $signalStore,
        string $projectionId,
        int $bucketFrom,
        string $workerId,
        int $limit = 100,
        ?Duration $suppressionTtl = null,
    ): ProjectionRunResult {
        $this->ensureInitialized($runtime);

        $segment = $this->segmentStore->claimSegment($projectionId, $bucketFrom, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        $snapshot = $signalStore->captureVisibilitySnapshot();
        $bucketRange = new BucketRange($segment->bucketFrom, $segment->bucketTo + 1);
        $suppression = null;

        try {
            $suppression = $this->trySuppressSignalDispatch(
                $signalStore,
                $projectionId,
                $bucketRange,
                $workerId,
                $snapshot->visibleAt->add($suppressionTtl ?? new Duration(seconds: 60)),
            );

            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = count($result->events) < $limit;
            $ackedSignals = 0;

            $this->transactionManager->transactional(function () use ($runtime, $segment, $result, $workerId, $caughtUp, $signalStore, $projectionId, $bucketRange, $snapshot, &$ackedSignals): void {
                foreach ($result->events as $event) {
                    $runtime->apply($event);
                }

                $runtime->flush();

                if ($caughtUp) {
                    $ackedSignals = $signalStore->ackVisibleInSnapshot($projectionId, $bucketRange, $snapshot);
                }

                $this->segmentStore->completeClaimedSegment($segment, $result->checkpoint, $workerId, $caughtUp);
            });

            return new ProjectionRunResult(
                processedEvents: count($result->events),
                claimedSegment: true,
                segment: $segment,
                caughtUp: $caughtUp,
                acknowledgedBuckets: $ackedSignals,
            );
        } catch (\Throwable $e) {
            $this->segmentStore->releaseClaimedSegment($segment, $workerId);

            throw $e;
        } finally {
            if ($suppression instanceof SuppressionHandle) {
                try {
                    $signalStore->releaseSuppression($suppression);
                } catch (\Throwable) {
                    // Suppression is advisory; a release failure must not change projection correctness.
                }
            }
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
            foreach ($this->segmentStore->listSegments($projectionId) as $segment) {
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

        $segment = $this->segmentStore->claimSegment($projectionId, $bucketFrom, $workerId);
        if (null === $segment) {
            return new ProjectionRunResult(0, false, null);
        }

        try {
            $query = $runtime->definition()->streamQueryForSegment($segment->bucketFrom, $segment->bucketTo);
            $result = $this->eventStore->load($query, $segment->checkpoint, null, $limit);
            $caughtUp = count($result->events) < $limit;

            $this->transactionManager->transactional(function () use ($runtime, $segment, $result, $workerId, $caughtUp): void {
                foreach ($result->events as $event) {
                    $runtime->apply($event);
                }

                $runtime->flush();
                $this->segmentStore->completeClaimedSegment($segment, $result->checkpoint, $workerId, $caughtUp);
            });

            return new ProjectionRunResult(
                processedEvents: count($result->events),
                claimedSegment: true,
                segment: $segment,
                caughtUp: $caughtUp,
            );
        } catch (\Throwable $e) {
            $this->segmentStore->releaseClaimedSegment($segment, $workerId);

            throw $e;
        }
    }

    private function trySuppressSignalDispatch(
        SignalStore $signalStore,
        string $queueName,
        BucketRange $bucketRange,
        string $workerId,
        DatePoint $suppressUntil,
    ): ?SuppressionHandle {
        try {
            return $signalStore->suppressDispatch(
                $queueName,
                $bucketRange,
                $workerId,
                $suppressUntil,
                sprintf('%s:%d-%d', $queueName, $bucketRange->lowerInclusive, $bucketRange->upperExclusive),
            );
        } catch (\Throwable) {
            return null;
        }
    }

}
