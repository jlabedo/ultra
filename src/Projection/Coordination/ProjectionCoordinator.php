<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;
use Ultra\Projection\ProjectionRuntime;
use Ultra\Projection\ProjectionSegment;

final readonly class ProjectionCoordinator
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionRunner $runner,
        private ProjectionBucketActivityRepositoryInterface $activityRepository,
    ) {}

    public function run(
        string $workerId,
        int $batchSize = 100,
        int $maxCycles = 32,
    ): ProjectionCoordinatorResult {
        $processedEvents = 0;
        $acknowledgedBuckets = 0;

        for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
            $assignment = $this->claimNextAssignment($workerId);
            if (null === $assignment) {
                break;
            }

            [$runtime, $projectionId, $bucketFrom] = $assignment;

            do {
                $result = $this->runner->runClaimedOnce($runtime, $projectionId, $bucketFrom, $workerId, $batchSize);
                if (!$result->claimedSegment) {
                    break;
                }

                $processedEvents += $result->processedEvents;
                $acknowledgedBuckets += $result->acknowledgedBuckets;
            } while (!$result->caughtUp);
        }

        return new ProjectionCoordinatorResult(
            $processedEvents,
            $acknowledgedBuckets,
        );
    }

    /**
     * @return null|array{ProjectionRuntime, string, int}
     */
    private function claimNextAssignment(string $workerId): ?array
    {
        $affectedBuckets = $this->activityRepository->affectedBuckets();
        if ([] === $affectedBuckets) {
            return null;
        }

        foreach ($this->groupByProjection($affectedBuckets) as $projectionId => $projectionBuckets) {
            $runtime = $this->catalog->runtime($projectionId);

            $this->runner->ensureInitialized($runtime);
            $this->runner->recoverExpiredClaims($projectionId);
            if ($runtime->definition()->isPartitioned()) {
                $this->splitAvailableSegmentsForPendingBuckets($projectionId, count($projectionBuckets));
            }

            foreach ($this->runner->availableSegments($projectionId) as $segment) {
                $pending = $this->activityRepository->pendingBucketsForSegment(
                    $projectionId,
                    $segment->bucketFrom,
                    $segment->bucketTo,
                );
                if ([] === $pending) {
                    continue;
                }

                $claimedSegment = $this->runner->claimSegment($projectionId, $segment->bucketFrom, $workerId);
                if (null !== $claimedSegment) {
                    return [$runtime, $projectionId, $segment->bucketFrom];
                }
            }
        }

        return null;
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

    private function splitAvailableSegmentsForPendingBuckets(string $projectionId, int $pendingBucketCount): void
    {
        $targetSegmentCount = max(1, $pendingBucketCount);

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
}
