<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionSegment;

final readonly class ProjectionCoordinator
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionRunner $runner,
        private ProjectionBucketActivityRepositoryInterface $activityRepository,
        private ProjectionCoordinatorLeaseRepositoryInterface $leaseRepository,
        private ProjectionActivatorInterface $activator,
        private ProjectionScalingPolicyInterface $scalingPolicy = new DefaultProjectionScalingPolicy(),
    ) {}

    public function run(
        string $workerId,
        int $batchSize = 100,
        int $maxCycles = 32,
    ): ProjectionCoordinatorResult {
        $acquiredLease = $this->leaseRepository->acquire($workerId);
        if (!$acquiredLease) {
            return new ProjectionCoordinatorResult(false, 0, 0, 0);
        }

        $processedEvents = 0;
        $acknowledgedBuckets = 0;
        $requestedWorkerActivations = 0;

        try {
            for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
                $this->leaseRepository->renew($workerId);

                $affectedBuckets = $this->activityRepository->affectedBuckets();
                if ([] === $affectedBuckets) {
                    break;
                }

                $additionalWorkers = $this->scalingPolicy->additionalWorkersRequested($affectedBuckets);
                $localAssignment = null;
                $remoteAssignments = 0;
                $claimedAny = false;

                foreach ($this->groupByProjection($affectedBuckets) as $projectionId => $_projectionBuckets) {
                    $runtime = $this->catalog->runtime($projectionId);

                    $this->runner->ensureInitialized($runtime);
                    $this->runner->recoverExpiredClaims($projectionId);
                    $this->splitUntilSegmentCount($projectionId, $additionalWorkers + 1);

                    foreach ($this->runner->availableSegments($projectionId) as $segment) {
                        $pending = $this->activityRepository->pendingBucketsForSegment(
                            $projectionId,
                            $segment->bucketFrom,
                            $segment->bucketTo,
                        );
                        if ([] === $pending) {
                            continue;
                        }

                        if (null === $localAssignment) {
                            $claimWorkerId = $workerId;
                        } elseif ($remoteAssignments < $additionalWorkers) {
                            $claimWorkerId = $this->workerIdFor($workerId, $projectionId, $segment->bucketFrom);
                        } else {
                            continue;
                        }

                        $claimedSegment = $this->runner->claimSegment($projectionId, $segment->bucketFrom, $claimWorkerId);
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
                    do {
                        $result = $this->runner->runClaimedOnce($runtime, $projectionId, $bucketFrom, $claimWorkerId, $batchSize);
                        if (!$result->claimedSegment) {
                            break;
                        }

                        $processedEvents += $result->processedEvents;
                        $acknowledgedBuckets += $result->acknowledgedBuckets;
                    } while (!$result->caughtUp);
                }

                if (!$claimedAny && null === $localAssignment) {
                    break;
                }
            }
        } finally {
            $this->leaseRepository->release($workerId);
        }

        return new ProjectionCoordinatorResult(
            true,
            $processedEvents,
            $acknowledgedBuckets,
            $requestedWorkerActivations,
        );
    }

    /**
     * @param list<ProjectionBucketActivity> $activities
     * @return array<string, list<ProjectionBucketActivity>>
     */
    private function groupByProjection(array $activities): array
    {
        $grouped = [];

        foreach ($activities as $activity) {
            $grouped[$activity->projectionId][] = $activity;
        }

        return $grouped;
    }

    private function splitUntilSegmentCount(string $projectionId, int $targetSegmentCount): void
    {
        while (count($this->runner->listSegments($projectionId)) < $targetSegmentCount) {
            $candidate = $this->largestSplittableSegment($projectionId);
            if (null === $candidate) {
                return;
            }

            $this->runner->splitSegment($projectionId, $candidate->bucketFrom);
        }
    }

    private function largestSplittableSegment(string $projectionId): ?ProjectionSegment
    {
        $candidates = array_filter(
            $this->runner->listSegments($projectionId),
            static fn (ProjectionSegment $segment): bool => null === $segment->workerId
                && $segment->bucketTo > $segment->bucketFrom,
        );
        usort(
            $candidates,
            static fn (ProjectionSegment $left, ProjectionSegment $right): int => ($right->bucketTo - $right->bucketFrom)
                <=> ($left->bucketTo - $left->bucketFrom),
        );

        return $candidates[0] ?? null;
    }

    private function workerIdFor(string $coordinatorWorkerId, string $projectionId, int $bucketFrom): string
    {
        return sprintf('%s-%s-%d', $coordinatorWorkerId, $projectionId, $bucketFrom);
    }
}
