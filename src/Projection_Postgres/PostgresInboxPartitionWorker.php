<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\EventFeed;
use Ultra\Projection\PartitionedDelivery;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionPlanValidator;

final readonly class PostgresInboxPartitionWorker
{
    public function __construct(
        private Connection $connection,
        private EventFeed $eventFeed,
        private PostgresProjectionRuntimeRepository $repository,
        private ?ProjectionPlanValidator $validator = null,
    ) {}

    public function runOnce(ProjectionPlan $plan, int $limit = 100): int
    {
        if (! $plan->delivery instanceof PartitionedDelivery) {
            throw new \InvalidArgumentException(sprintf(
                'Projection "%s" uses direct delivery and must not be run by the inbox partition worker.',
                $plan->name(),
            ));
        }

        $this->validator?->validate($plan);

        $claimedPartition = null;
        $this->connection->beginTransaction();

        try {
            $claimedPartition = $this->repository->claimNextPartition($plan->name(), $plan->version());
            if (null === $claimedPartition) {
                $this->connection->commit();

                return 0;
            }

            $eventIds = $this->repository->pendingEventIds(
                $plan->name(),
                $plan->version(),
                $claimedPartition->partitionKey,
                $claimedPartition->lastEventId,
                $limit,
            );

            if ([] === $eventIds) {
                $this->connection->commit();

                return 0;
            }

            $events = $this->eventFeed->readByIds($eventIds);
            if (count($events) !== count($eventIds)) {
                throw new \RuntimeException(sprintf(
                    'Projection "%s" expected %d events for partition %s but only loaded %d.',
                    $plan->name(),
                    count($eventIds),
                    $claimedPartition->partitionKey,
                    count($events),
                ));
            }

            foreach ($events as $event) {
                $plan->apply($event);
            }

            $lastEventId = $events[array_key_last($events)]->eventId;
            $this->repository->advancePartition(
                $plan->name(),
                $plan->version(),
                $claimedPartition->partitionKey,
                $lastEventId,
            );
            $this->repository->deleteInboxThrough(
                $plan->name(),
                $plan->version(),
                $claimedPartition->partitionKey,
                $lastEventId,
            );

            $this->connection->commit();

            return count($events);
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            if (null !== $claimedPartition) {
                $this->repository->recordPartitionError(
                    $plan->name(),
                    $plan->version(),
                    $claimedPartition->partitionKey,
                    $exception,
                );
            }

            throw $exception;
        }
    }
}
