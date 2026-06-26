<?php

declare(strict_types=1);

namespace Ultra\Projection\Signal;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Projection\ProjectionCatalog;
use Ultra\Signal\SignalStore;

final readonly class ProjectionSignalEventStore implements EventStore
{
    public function __construct(
        private EventStore $inner,
        private ProjectionCatalog $catalog,
        private SignalStore $signalStore,
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return $this->inner->load($query, $from, $to, $batchSize);
    }

    /**
     * @param list<Event> $events
     */
    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        $this->inner->append($events, ...$appendConditions);
        $this->recordSignals($events);
    }

    /**
     * @param list<Event> $events
     */
    private function recordSignals(array $events): void
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

        foreach ($touchedBuckets as $projectionId => $buckets) {
            foreach (array_keys($buckets) as $bucket) {
                $this->signalStore->signal(
                    queueName: $projectionId,
                    bucket: (int) $bucket,
                    signalKey: sprintf('%s:%d', $projectionId, $bucket),
                );
            }
        }
    }
}
