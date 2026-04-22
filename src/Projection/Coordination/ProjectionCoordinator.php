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
        string $projectionId,
        string $workerId,
        int $batchSize = 100,
        int $maxCycles = 32,
    ): ProjectionCoordinatorResult {
        if (!$this->leaseRepository->acquire($projectionId, $workerId)) {
            return new ProjectionCoordinatorResult(false, 0, 0, 0);
        }

        $processedEvents = 0;
        $acknowledgedBuckets = 0;
        $queuedWorkerWakeups = 0;
        $runtime = $this->catalog->runtime($projectionId);
        $definition = $runtime->definition();

        try {
            for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
                $this->leaseRepository->renew($projectionId, $workerId);
                $this->runner->recoverExpiredLeases($projectionId);

                $pendingBuckets = $this->activityRepository->pendingBuckets($projectionId);
                if ([] === $pendingBuckets) {
                    break;
                }

                $queued = $this->scalingPolicy->additionalWorkerWakeups($definition, $pendingBuckets);
                for ($i = 0; $i < $queued; ++$i) {
                    $this->wakeQueue->enqueue(new WakeProjectionWorker($projectionId));
                    ++$queuedWorkerWakeups;
                }

                $progressed = false;
                foreach ($pendingBuckets as $bucketActivity) {
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
            $this->leaseRepository->release($projectionId, $workerId);
        }

        return new ProjectionCoordinatorResult(true, $processedEvents, $acknowledgedBuckets, $queuedWorkerWakeups);
    }
}
