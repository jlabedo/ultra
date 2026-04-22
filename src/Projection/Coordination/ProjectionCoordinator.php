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
        private ProjectionActivatorInterface $activator,
        private ProjectionScalingPolicyInterface $scalingPolicy = new DefaultProjectionScalingPolicy(),
    ) {}

    public function run(
        string $workerId,
        int $batchSize = 100,
        int $maxCycles = 32,
    ): ProjectionCoordinatorResult {
        $acquiredLease = $this->leaseRepository->acquire($workerId);

        $processedEvents = 0;
        $acknowledgedBuckets = 0;
        $requestedWorkerActivations = 0;

        try {
            for ($cycle = 0; $cycle < $maxCycles; ++$cycle) {
                if ($acquiredLease) {
                    $this->leaseRepository->renew($workerId);
                }

                $affectedBuckets = $this->activityRepository->affectedBuckets();
                if ([] === $affectedBuckets) {
                    break;
                }

                if ($acquiredLease) {
                    $requested = $this->scalingPolicy->additionalWorkersRequested($affectedBuckets);
                    $this->activator->requestAdditionalWorkers($requested);
                    $requestedWorkerActivations += $requested;
                }

                $progressed = false;
                $coordinatedProjectionIds = [];
                foreach ($affectedBuckets as $bucketActivity) {
                    $projectionId = $bucketActivity->projectionId;
                    if ($acquiredLease && !isset($coordinatedProjectionIds[$projectionId])) {
                        $this->runner->recoverExpiredLeases($projectionId);
                        $this->runner->reconcilePendingOperations($projectionId);
                        $coordinatedProjectionIds[$projectionId] = true;
                    }

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
            if ($acquiredLease) {
                $this->leaseRepository->release($workerId);
            }
        }

        return new ProjectionCoordinatorResult(
            $acquiredLease,
            $processedEvents,
            $acknowledgedBuckets,
            $requestedWorkerActivations,
        );
    }
}
