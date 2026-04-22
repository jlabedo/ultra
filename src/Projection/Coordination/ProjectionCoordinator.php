<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Projection\ProjectionCatalog;
use Ultra\Projection\ProjectionRunner;

final readonly class ProjectionCoordinator
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionRunner $runner,
        private ProjectionBucketActivityRepositoryInterface $activityRepository,
        private ProjectionCoordinatorLeaseRepositoryInterface $leaseRepository,
        private ProjectionWakeQueueInterface $wakeQueue,
        private ProjectionScalingPolicyInterface $scalingPolicy = new DefaultProjectionScalingPolicy(),
    ) {}

    public function run(
        string $workerId,
        int $batchSize = 100,
        int $maxCycles = 32,
    ): ProjectionCoordinatorResult {
        if (!$this->leaseRepository->acquire($workerId)) {
            return new ProjectionCoordinatorResult(false, 0, 0, 0);
        }

        $processedEvents = 0;
        $acknowledgedBuckets = 0;
        $queuedWorkerWakeups = 0;

        try {
            for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
                $this->leaseRepository->renew($workerId);

                $affectedBuckets = $this->activityRepository->affectedBuckets();
                if ([] === $affectedBuckets) {
                    break;
                }

                $queued = $this->scalingPolicy->additionalWorkerWakeups($affectedBuckets);
                $queuedProjectionIds = [];
                foreach ($affectedBuckets as $affectedBucket) {
                    $queuedProjectionIds[$affectedBucket->projectionId] = true;
                }
                $queuedProjectionIds = array_values(array_keys($queuedProjectionIds));
                for ($i = 0; $i < $queued; ++$i) {
                    $projectionId = $queuedProjectionIds[$i % count($queuedProjectionIds)];
                    $this->wakeQueue->enqueue(new WakeProjectionWorker($projectionId));
                    ++$queuedWorkerWakeups;
                }

                $progressed = false;
                $recoveredProjectionIds = [];
                foreach ($affectedBuckets as $affectedBucket) {
                    $projectionId = $affectedBucket->projectionId;
                    if (isset($recoveredProjectionIds[$projectionId])) {
                        continue;
                    }

                    $this->runner->recoverExpiredLeases($projectionId);
                    $recoveredProjectionIds[$projectionId] = true;
                }

                foreach ($affectedBuckets as $bucketActivity) {
                    $projectionId = $bucketActivity->projectionId;
                    $runtime = $this->catalog->runtime($projectionId);
                    $result = $this->runner->runBucketOnceResult(
                        $runtime,
                        $bucketActivity->bucket,
                        $workerId . '-segment',
                        $batchSize,
                    );

                    if (!$result->claimedSegment) {
                        continue;
                    }

                    $this->activityRepository->acknowledge(
                        $projectionId,
                        $bucketActivity->bucket,
                        $bucketActivity->counter,
                    );

                    $processedEvents += $result->processedEvents;
                    ++$acknowledgedBuckets;
                    $progressed = true;
                }

                if (!$progressed) {
                    break;
                }
            }
        } finally {
            $this->leaseRepository->release($workerId);
        }

        return new ProjectionCoordinatorResult(true, $processedEvents, $acknowledgedBuckets, $queuedWorkerWakeups);
    }
}
