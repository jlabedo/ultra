<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\EventStore\Checkpoint;
use Ultra\Projection\EventFeed;
use Ultra\Projection\ProjectionPlan;

final readonly class PostgresInboxPartitionWorker
{
    private const WORKER_LANE = 'partition_worker';

    public function __construct(
        private Connection $connection,
        private EventFeed $eventFeed,
        private PostgresProjectionRuntimeRepository $repository,
    ) {}

    public function runOnce(ProjectionPlan $plan, int $limit = 100): int
    {
        $this->connection->beginTransaction();

        try {
            $errorScope = ['type' => 'state', 'key' => self::WORKER_LANE];
            $partitionState = $this->repository->claimNextPartition($plan->name(), $plan->version());
            if (null === $partitionState) {
                $this->connection->commit();

                return 0;
            }

            $errorScope = ['type' => 'partition', 'key' => $partitionState->partitionKey];
            $eventIds = $this->repository->pendingEventIds(
                $plan->name(),
                $plan->version(),
                $partitionState->partitionKey,
                $partitionState->lastEventId,
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
                    $partitionState->partitionKey,
                    count($events),
                ));
            }

            foreach ($events as $event) {
                $plan->apply($event);
            }

            $lastEventId = $events[array_key_last($events)]->eventId;
            $this->repository->advancePartitionState(
                $plan->name(),
                $plan->version(),
                $partitionState->partitionKey,
                $lastEventId,
            );
            $this->repository->deleteInboxThrough(
                $plan->name(),
                $plan->version(),
                $partitionState->partitionKey,
                $lastEventId,
            );

            $this->connection->commit();

            return count($events);
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            if ('partition' === $errorScope['type']) {
                $this->repository->recordPartitionError(
                    $plan->name(),
                    $plan->version(),
                    $errorScope['key'],
                    $exception,
                );
            } else {
                $this->recordProjectionErrorBestEffort($plan, $errorScope['key'], $exception);
            }

            throw $exception;
        }
    }

    private function recordProjectionErrorBestEffort(ProjectionPlan $plan, string $lane, \Throwable $exception): void
    {
        try {
            $this->repository->recordProjectionError(
                new ProjectionState($plan->name(), $plan->version(), $lane, Checkpoint::beginning(), false),
                $exception,
            );
        } catch (\Throwable) {
            // Preserve the original failure if the post-rollback error write also fails.
        }
    }
}
