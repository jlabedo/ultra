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
        $this->transactions->transactional(function () use ($events, $appendConditions): void {
            $this->states->lockRegistrationShared();
            $live = [];
            foreach ($this->catalog->synchronousRuntimes() as $runtime) {
                $definition = $runtime->definition();
                $matching = array_values(array_filter($events, $definition->handles(...)));
                if ([] === $matching) {
                    continue;
                }
                foreach ($matching as $event) {
                    if (!isset($event->domainIds[$definition->partitionDimension])) {
                        throw new \InvalidArgumentException(
                            'Managed projection events must carry their partition identifier.',
                        );
                    }
                }
                // Lock even an uninitialized projection: registration must not race this append.
                $this->states->lockShared($definition->projectionId);
                if (ProjectionPhase::Live === $this->states->find($definition->projectionId)?->phase) {
                    $live[] = [$runtime, $matching];
                }
            }
            $this->assertCompatibleWriter();
            $this->inner->append($events, ...$appendConditions);
            $applied = [];
            foreach ($live as [$runtime, $matching]) {
                $this->batches->execute($runtime, $matching);
                $applied[] = $runtime->projectionId();
            }
            $this->activityRecorder->record($events, $applied);
        });
    }

    private function assertCompatibleWriter(): void
    {
        foreach ($this->states->all() as $state) {
            try {
                $definition = $this->catalog->definition($state->projectionId);
            } catch (\InvalidArgumentException $e) {
                throw new \LogicException(
                    'Writer is missing managed projection "' . $state->projectionId . '".',
                    previous: $e,
                );
            }
            if (ProjectionMode::Synchronous !== $definition->mode || $definition->fingerprint !== $state->fingerprint) {
                throw new \LogicException(
                    'Writer has an incompatible managed projection "' . $state->projectionId . '".',
                );
            }
        }
    }
}
