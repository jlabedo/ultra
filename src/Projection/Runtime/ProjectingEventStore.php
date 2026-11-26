<?php

declare(strict_types=1);

namespace Ultra\Projection;

use Ultra\EventStore\AppendCondition;
use Ultra\EventStore\Checkpoint;
use Ultra\EventStore\Event;
use Ultra\EventStore\EventStore;
use Ultra\EventStore\LoadResult;
use Ultra\EventStore\StreamQuery;
use Ultra\Projection\Coordination\ProjectionActivityRecorder;

/** The single projection integration point for direct and unit-of-work appends. */
final readonly class ProjectingEventStore implements EventStore
{
    public function __construct(
        private EventStore $inner,
        private ProjectionCatalog $catalog,
        private ProjectionActivityRecorder $activityRecorder,
        private ProjectionTransactionManagerInterface $transactions,
        private ProjectionStateRepository $states,
        private ProjectionBatchExecutor $batches = new ProjectionBatchExecutor(),
    ) {}

    public function load(
        StreamQuery $query,
        ?Checkpoint $from = null,
        ?Checkpoint $to = null,
        int $batchSize = 1000,
    ): LoadResult {
        return $this->inner->load($query, $from, $to, $batchSize);
    }

    /** @param list<Event> $events */
    public function append(array $events, AppendCondition ...$appendConditions): void
    {
        if ([] === $events) {
            $this->inner->append($events, ...$appendConditions);

            return;
        }
        $candidates = [];
        foreach ($this->catalog->synchronousRuntimes() as $runtime) {
            $matching = array_values(array_filter($events, $runtime->definition()->handles(...)));
            if ([] !== $matching) {
                $candidates[] = [$runtime, $matching];
            }
        }

        if ([] === $candidates) {
            $buckets = $this->activityRecorder->touchedBuckets($events);
            $this->transactions->transactional(function () use ($events, $appendConditions, $buckets): void {
                $this->inner->append($events, ...$appendConditions);
                $this->activityRecorder->recordBuckets($buckets);
            }, startIfNeeded: [] !== $buckets);

            return;
        }

        $this->transactions->transactional(function () use ($events, $appendConditions, $candidates): void {
            $this->states->lockRegistrationShared();
            $projectionIds = [];
            foreach ($candidates as [$runtime, $matching]) {
                $definition = $runtime->definition();
                foreach ($matching as $event) {
                    if (!isset($event->domainIds[$definition->partitionDimension])) {
                        throw new \InvalidArgumentException(
                            'Managed projection events must carry their partition identifier.',
                        );
                    }
                }
                // Lock uninitialized matches too so registration cannot race this append.
                $this->states->lockShared($definition->projectionId);
                $projectionIds[] = $definition->projectionId;
            }
            $states = $this->states->findMany($projectionIds);
            $live = [];
            foreach ($candidates as [$runtime, $matching]) {
                $id = $runtime->projectionId();
                $state = $states[$id] ?? null;
                if (null === $state) {
                    continue;
                }
                if ($runtime->definition()->fingerprint !== $state->fingerprint) {
                    throw new \LogicException('Writer has an incompatible managed projection "' . $id . '".');
                }
                if (ProjectionPhase::Live === $state->phase) {
                    $live[] = [$runtime, $matching];
                }
            }
            $this->inner->append($events, ...$appendConditions);
            $applied = [];
            foreach ($live as [$runtime, $matching]) {
                $this->batches->execute($runtime, $matching);
                $applied[] = $runtime->projectionId();
            }
            $this->activityRecorder->record($events, $applied);
        });
    }
}
