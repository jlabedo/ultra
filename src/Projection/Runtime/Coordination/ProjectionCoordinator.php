<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\Coordination\Tracking\PendingKey;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRunResult;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionSegment;

final readonly class ProjectionCoordinator
{
    /**
     * Coordinator-lease key for the single global projection coordinator.
     */
    public const string COORDINATOR_KEY = 'projection-coordinator';

    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionRunner $runner,
        private ProjectionActivity $activity,
        private CoordinatorLease $leaseRepository,
        private ProjectionActivatorInterface $activator,
        private ProjectionScalingPolicyInterface $scalingPolicy = new DefaultProjectionScalingPolicy(),
    ) {}

    public function run(string $workerId, int $batchSize = 100, int $maxCycles = 32): ProjectionCoordinatorResult
    {
        $acquiredLease = $this->leaseRepository->acquire(self::COORDINATOR_KEY, $workerId);
        if (!$acquiredLease) {
            return new ProjectionCoordinatorResult(false, 0, 0, 0);
        }

        $processedEvents = 0;
        $acknowledgedBuckets = 0;
        $requestedWorkerActivations = 0;
        $localPlan = [];

        try {
            for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
                $this->leaseRepository->renew(self::COORDINATOR_KEY, $workerId);

                if ($this->scalingPolicy instanceof DefaultProjectionScalingPolicy) {
                    $freshPlan = [] === $localPlan;
                    if ($freshPlan) {
                        $localPlan = $this->groupByProjection($this->activity->affected());
                    }

                    $claimed = false;
                    foreach ($this->runLocalAssignment($localPlan, $workerId, $batchSize) as $result) {
                        $claimed = true;
                        $processedEvents += $result->processedEvents;
                        $acknowledgedBuckets += $result->acknowledgedBuckets;
                    }
                    if (!$claimed && $freshPlan) {
                        break;
                    }

                    continue;
                }

                $affectedBuckets = $this->activity->affected();
                if ([] === $affectedBuckets) {
                    break;
                }

                $additionalWorkers = $this->scalingPolicy->additionalWorkersRequested($affectedBuckets);
                $localAssignment = null;
                $remoteAssignments = 0;
                $claimedAny = false;

                foreach ($this->groupByProjection($affectedBuckets) as $projectionId => $projectionBuckets) {
                    $runtime = $this->catalog->runtime($projectionId);
                    if (!$this->runner->isReplayable($runtime)) {
                        $this->drainObsoleteActivity($projectionId, $projectionBuckets);

                        continue;
                    }

                    $this->runner->ensureInitialized($runtime);
                    $this->runner->recoverExpiredClaims($projectionId);
                    $this->splitUntilSegmentCount($runtime, $additionalWorkers + 1);

                    foreach ($this->runner->availableSegments($projectionId) as $segment) {
                        $pending = $this->activity->pendingInSegment(
                            $projectionId,
                            $segment->bucketFrom,
                            $segment->bucketTo,
                        );
                        if ([] === $pending) {
                            continue;
                        }

                        if (null !== $localAssignment && $remoteAssignments >= $additionalWorkers) {
                            continue;
                        }
                        $claimWorkerId = null === $localAssignment
                            ? $workerId
                            : $this->workerIdFor($workerId, $projectionId, $segment->bucketFrom);

                        $claimedSegment = $this->runner->claimSegment($runtime, $segment->bucketFrom, $claimWorkerId);
                        if (null === $claimedSegment) {
                            continue;
                        }

                        $claimedAny = true;
                        if (null === $localAssignment) {
                            $localAssignment = [$runtime, $projectionId, $segment->bucketFrom, $workerId];

                            continue;
                        }

                        $this->activator->activateWorker($projectionId, $segment->bucketFrom, $claimWorkerId);
                        ++$remoteAssignments;
                        ++$requestedWorkerActivations;
                    }
                }

                if (null !== $localAssignment) {
                    [$runtime, $projectionId, $bucketFrom, $claimWorkerId] = $localAssignment;
                    foreach ($this->runner->runClaimedBatches(
                        $runtime,
                        $projectionId,
                        $bucketFrom,
                        $claimWorkerId,
                        $batchSize,
                    ) as $result) {
                        $processedEvents += $result->processedEvents;
                        $acknowledgedBuckets += $result->acknowledgedBuckets;
                    }
                }

                if (!$claimedAny && null === $localAssignment) {
                    break;
                }
            }
        } finally {
            $this->leaseRepository->release(self::COORDINATOR_KEY, $workerId);
        }

        return new ProjectionCoordinatorResult(
            true,
            $processedEvents,
            $acknowledgedBuckets,
            $requestedWorkerActivations,
        );
    }

    /**
     * Consume candidates from the same poll across local assignments. Claim only when ready to run.
     *
     * @param array<string, list<PendingKey>> $plan
     *
     * @return \Generator<int, ProjectionRunResult, void, void>
     */
    private function runLocalAssignment(array &$plan, string $workerId, int $batchSize): \Generator
    {
        foreach ($plan as $projectionId => $projectionBuckets) {
            unset($plan[$projectionId]);
            $runtime = $this->catalog->runtime($projectionId);
            if (!$this->runner->isReplayable($runtime)) {
                $this->drainObsoleteActivity($projectionId, $projectionBuckets);

                continue;
            }

            foreach ($this->localBucketStarts($projectionId) as $bucketFrom) {
                $claimed = false;
                foreach ($this->runner->runClaimedBatches(
                    $runtime,
                    $projectionId,
                    $bucketFrom,
                    $workerId,
                    $batchSize,
                ) as $result) {
                    if (!$result->claimedSegment) {
                        break;
                    }
                    $claimed = true;

                    yield $result;
                }
                if ($claimed) {
                    return;
                }
            }
        }
    }

    /** @return \Generator<int, int, void, void> */
    private function localBucketStarts(string $projectionId): \Generator
    {
        $this->runner->recoverExpiredClaims($projectionId);
        $segments = $this->runner->listSegments($projectionId);
        if ([] === $segments) {
            yield 0;

            return;
        }

        foreach ($segments as $segment) {
            if (null !== $segment->workerId) {
                continue;
            }
            // For the full range, the global poll already found work for this segment.
            if (
                0 === $segment->bucketFrom && 255 === $segment->bucketTo
                || [] !== $this->activity->pendingInSegment($projectionId, $segment->bucketFrom, $segment->bucketTo)
            ) {
                yield $segment->bucketFrom;
            }
        }
    }

    /** @param list<PendingKey> $projectionBuckets */
    private function drainObsoleteActivity(string $projectionId, array $projectionBuckets): void
    {
        $seen = [];
        foreach ($projectionBuckets as $activity) {
            $seen[(int) $activity->key] = $activity->counter;
        }
        $this->activity->drainSeen($projectionId, $seen);
    }

    /**
     * @param list<PendingKey> $activities
     *
     * @return array<string, list<PendingKey>>
     */
    private function groupByProjection(array $activities): array
    {
        $grouped = [];

        foreach ($activities as $activity) {
            $grouped[$activity->channel][] = $activity;
        }

        return $grouped;
    }

    private function splitUntilSegmentCount(ProjectionRuntime $runtime, int $targetSegmentCount): void
    {
        $projectionId = $runtime->projectionId();
        while (count($this->runner->listSegments($projectionId)) < $targetSegmentCount) {
            $candidate = $this->largestSplittableSegment($projectionId);
            if (null === $candidate) {
                return;
            }

            $this->runner->splitSegment($runtime, $candidate->bucketFrom);
        }
    }

    private function largestSplittableSegment(string $projectionId): ?ProjectionSegment
    {
        $candidates = array_filter(
            $this->runner->listSegments($projectionId),
            static fn(ProjectionSegment $segment): bool => (
                null === $segment->workerId
                && $segment->bucketTo > $segment->bucketFrom
            ),
        );
        usort(
            $candidates,
            static fn(ProjectionSegment $left, ProjectionSegment $right): int => (
                ($right->bucketTo - $right->bucketFrom) <=> ($left->bucketTo - $left->bucketFrom)
            ),
        );

        return $candidates[0] ?? null;
    }

    private function workerIdFor(string $coordinatorWorkerId, string $projectionId, int $bucketFrom): string
    {
        return sprintf('%s-%s-%d', $coordinatorWorkerId, $projectionId, $bucketFrom);
    }
}
