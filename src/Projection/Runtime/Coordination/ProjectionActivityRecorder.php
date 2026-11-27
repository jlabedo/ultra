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
        $this->recordBuckets($this->touchedBuckets($events, $excludedProjections));
    }

    /**
     * @param list<Event> $events
     * @param list<string> $excludedProjections
     * @return array<array-key, non-empty-array<int, true>>
     */
    public function touchedBuckets(array $events, array $excludedProjections = []): array
    {
        $touchedBuckets = [];

        foreach ($this->catalog->definitions() as $definition) {
            if (in_array($definition->projectionId, $excludedProjections, true)) {
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

        return $touchedBuckets;
    }

    /** @param array<array-key, non-empty-array<int, true>> $touchedBuckets */
    public function recordBuckets(array $touchedBuckets): void
    {
        if ([] === $touchedBuckets) {
            return;
        }

        $this->activity->bumpBuckets($touchedBuckets);

        if ($this->leaseRepository->markWakeEnqueued(
            ProjectionCoordinator::COORDINATOR_KEY,
            $this->wakeDebounceSeconds,
        )) {
            $this->activator->signalWorkAvailable();
        }
    }
}
