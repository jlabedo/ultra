<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionWorkAssignment;

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
                $localAssignments = [];
                $remoteAssignments = 0;
                $assignedAny = false;

                foreach ($this->groupByProjection($affectedBuckets) as $projectionId => $_projectionBuckets) {
                    $runtime = $this->catalog->runtime($projectionId);

                    $this->runner->ensureInitialized($runtime);
                    $this->runner->recoverExpiredAssignments($projectionId);
                    $this->splitUntilSegmentCount($projectionId, $additionalWorkers + 1);

                    foreach ($this->runner->availableSegments($projectionId) as $segment) {
                        $pending = $this->activityRepository->pendingBucketsForSegment(
                            $projectionId,
                            $segment['bucketFrom'],
                            $segment['bucketTo'],
                        );
                        if ([] === $pending) {
                            continue;
                        }

                        if ([] === $localAssignments) {
                            $assignmentWorkerId = $workerId;
                        } elseif ($remoteAssignments < $additionalWorkers) {
                            $assignmentWorkerId = $this->workerIdFor($workerId, $projectionId, $segment['bucketFrom']);
                        } else {
                            continue;
                        }

                        $assignment = $this->runner->assignSegment($projectionId, $segment['bucketFrom'], $assignmentWorkerId);
                        if (null === $assignment) {
                            continue;
                        }

                        $assignedAny = true;
                        if ($assignment->workerId === $workerId && [] === $localAssignments) {
                            $localAssignments[] = [$runtime, $assignment];
                            continue;
                        }

                        $this->activator->activateWorker($assignment);
                        ++$remoteAssignments;
                        ++$requestedWorkerActivations;
                    }
                }

                foreach ($localAssignments as [$runtime, $assignment]) {
                    /** @var ProjectionWorkAssignment $assignment */
                    do {
                        $result = $this->runner->runAssignedOnce($runtime, $assignment, $batchSize);
                        if (!$result->claimedSegment) {
                            break;
                        }

                        $processedEvents += $result->processedEvents;
                        $acknowledgedBuckets += $result->acknowledgedBuckets;
                    } while (!$result->caughtUp);
                }

                if (!$assignedAny && [] === $localAssignments) {
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

            $this->runner->splitSegment($projectionId, $candidate['bucketFrom']);
        }
    }

    /** @return null|array{bucketFrom: int, bucketTo: int, workerId: null|string} */
    private function largestSplittableSegment(string $projectionId): ?array
    {
        $candidates = array_filter(
            $this->runner->listSegments($projectionId),
            static fn (array $segment): bool => null === $segment['workerId']
                && $segment['bucketTo'] > $segment['bucketFrom'],
        );
        usort(
            $candidates,
            static fn (array $left, array $right): int => ($right['bucketTo'] - $right['bucketFrom'])
                <=> ($left['bucketTo'] - $left['bucketFrom']),
        );

        return $candidates[0] ?? null;
    }

    private function workerIdFor(string $coordinatorWorkerId, string $projectionId, int $bucketFrom): string
    {
        return sprintf('%s-%s-%d', $coordinatorWorkerId, $projectionId, $bucketFrom);
    }
}
