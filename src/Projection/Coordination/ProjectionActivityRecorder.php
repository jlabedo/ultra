<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\EventStore\Event;
use Ultra\Projection\ProjectionCatalog;

final readonly class ProjectionActivityRecorder
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionBucketActivityRepositoryInterface $activityRepository,
        private ProjectionCoordinatorLeaseRepositoryInterface $leaseRepository,
        private ProjectionActivatorInterface $activator,
        private int $wakeDebounceSeconds = 30,
    ) {}

    /** @param list<Event> $events */
    public function record(array $events): void
    {
        $touchedBuckets = [];

        foreach ($this->catalog->definitions() as $definition) {
            foreach ($events as $event) {
                if (!$definition->handles($event)) {
                    continue;
                }

                $bucket = $definition->bucketFor($event);
                if (null === $bucket) {
                    continue;
                }

                $touchedBuckets[$definition->projectionId][$bucket] = true;
            }
        }

        $recordedAnyBucket = false;

        foreach ($touchedBuckets as $projectionId => $buckets) {
            foreach (array_keys($buckets) as $bucket) {
                $this->activityRepository->bump($projectionId, (int) $bucket);
                $recordedAnyBucket = true;
            }
        }

        if ($recordedAnyBucket && $this->leaseRepository->markWakeEnqueued($this->wakeDebounceSeconds)) {
            $this->activator->signalWorkAvailable();
        }
    }
}
