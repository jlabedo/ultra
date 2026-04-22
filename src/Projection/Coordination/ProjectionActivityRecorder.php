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
        private ProjectionWakeQueueInterface $wakeQueue,
        private int $wakeDebounceSeconds = 30,
    ) {}

    /** @param list<Event> $events */
    public function record(array $events): void
    {
        $touchedBuckets = [];

        foreach ($this->catalog->descriptors() as $descriptor) {
            foreach ($events as $event) {
                if (!$descriptor->handles($event)) {
                    continue;
                }

                $bucket = $descriptor->bucketFor($event);
                if (null === $bucket) {
                    continue;
                }

                $touchedBuckets[$descriptor->projectionId][$bucket] = true;
            }
        }

        foreach ($touchedBuckets as $projectionId => $buckets) {
            foreach (array_keys($buckets) as $bucket) {
                $this->activityRepository->bump($projectionId, (int) $bucket);
            }

            if ($this->leaseRepository->markWakeEnqueued($projectionId, $this->wakeDebounceSeconds)) {
                $this->wakeQueue->enqueue(new WakeProjectionCoordinator($projectionId));
            }
        }
    }
}
