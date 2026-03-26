<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\EventFeed;
use Ultra\Projection\PartitionedDelivery;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionPlanValidator;

final readonly class PostgresProjectionRouter
{
    private const LANE = 'router';

    public function __construct(
        private Connection $connection,
        private EventFeed $eventFeed,
        private PostgresProjectionRuntimeRepository $repository,
        private ?ProjectionPlanValidator $validator = null,
    ) {}

    public function routeOnce(ProjectionPlan $plan, int $limit = 100): int
    {
        if (! $plan->delivery instanceof PartitionedDelivery) {
            throw new \InvalidArgumentException(sprintf(
                'Projection "%s" uses direct delivery and must not be run by the router.',
                $plan->name(),
            ));
        }

        $this->validator?->validate($plan);

        $this->connection->beginTransaction();

        try {
            $lastEventId = $this->repository->lockStateLastEventId($plan->name(), $plan->version(), self::LANE);
            $events = $this->eventFeed->readAfter($lastEventId, $limit, $plan->eventTypes);

            if ([] === $events) {
                $this->connection->commit();

                return 0;
            }

            $rows = [];
            foreach ($events as $event) {
                $partitionKey = $plan->delivery->strategy->keyFor($event);
                $this->repository->ensurePartitionRow($plan->name(), $plan->version(), $partitionKey);
                $rows[] = [$partitionKey, $event->eventId];
            }

            $this->repository->enqueueEvents($plan->name(), $plan->version(), $rows);
            $this->repository->advanceState(
                $plan->name(),
                $plan->version(),
                self::LANE,
                $events[array_key_last($events)]->eventId,
            );

            $this->connection->commit();

            return count($events);
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $this->repository->recordStateError($plan->name(), $plan->version(), self::LANE, $exception);

            throw $exception;
        }
    }
}
