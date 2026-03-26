<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\DirectDelivery;
use Ultra\Projection\EventFeed;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionPlanValidator;

final readonly class PostgresDirectProjectionRunner
{
    private const LANE = 'direct';

    public function __construct(
        private Connection $connection,
        private EventFeed $eventFeed,
        private PostgresProjectionRuntimeRepository $repository,
        private ?ProjectionPlanValidator $validator = null,
    ) {}

    public function runOnce(ProjectionPlan $plan, int $limit = 100): int
    {
        if (! $plan->delivery instanceof DirectDelivery) {
            throw new \InvalidArgumentException(sprintf(
                'Projection "%s" uses partitioned delivery and must not be run by the direct runner.',
                $plan->name(),
            ));
        }

        $this->validator?->validate($plan);

        $state = null;
        $this->connection->beginTransaction();

        try {
            $state = $this->repository->lockProjectionState($plan->name(), $plan->version(), self::LANE);
            if ($state->wasCreated && $plan->hasProjectionInitializer()) {
                $plan->initializeProjection();
            }

            $events = $this->eventFeed->readAfter($state->lastEventId, $limit, $plan->eventTypes);

            if ([] === $events) {
                $this->connection->commit();

                return 0;
            }

            foreach ($events as $event) {
                $plan->apply($event);
            }

            $this->repository->advanceProjectionState($state, $events[array_key_last($events)]->eventId);

            $this->connection->commit();

            return count($events);
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->recordProjectionErrorBestEffort($plan, $state, $exception);

            throw $exception;
        }
    }

    private function recordProjectionErrorBestEffort(ProjectionPlan $plan, ?ProjectionState $state, \Throwable $exception): void
    {
        try {
            $this->repository->recordProjectionError(
                $state ?? new ProjectionState($plan->name(), $plan->version(), self::LANE, 0, false),
                $exception,
            );
        } catch (\Throwable) {
            // Preserve the original failure if the post-rollback error write also fails.
        }
    }
}
