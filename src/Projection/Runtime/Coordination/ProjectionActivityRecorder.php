<?php

declare(strict_types=1);

namespace Ultra\Projection\Coordination;

use Ultra\Coordination\Lease\CoordinatorLease;
use Ultra\EventStore\Event;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Time\Duration;

final readonly class ProjectionActivityRecorder
{
    public function __construct(
        private ProjectionCatalog $catalog,
        private ProjectionActivity $activity,
        private CoordinatorLease $leaseRepository,
        private ProjectionActivatorInterface $activator,
        private Duration $wakeDebounceSeconds = new Duration(seconds: 30),
    ) {}

    /**
     * @param list<Event> $events
     * @param list<string> $excludedProjections Already applied synchronously.
     */
    public function record(array $events, array $excludedProjections = []): void
    {
        $touchedBuckets = [];

        foreach ($this->catalog->definitions() as $definition) {
            if (
                in_array($definition->projectionId, $excludedProjections, true)
                || \Ultra\Projection\ProjectionMode::SynchronousFromNow === $definition->mode
            ) {
                continue;
            }
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
                $this->activity->bump($projectionId, (int) $bucket);
                $recordedAnyBucket = true;
            }
        }

        if (
            $recordedAnyBucket
            && $this->leaseRepository->markWakeEnqueued(
                ProjectionCoordinator::COORDINATOR_KEY,
                $this->wakeDebounceSeconds,
            )
        ) {
            $this->activator->signalWorkAvailable();
        }
    }
}
