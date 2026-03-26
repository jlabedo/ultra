<?php

declare(strict_types=1);

namespace Ultra\Projection_Postgres;

use Doctrine\DBAL\Connection;
use Ultra\Projection\DirectDelivery;
use Ultra\Projection\EventFeed;
use Ultra\Projection\ProjectionEnvelopeFactory;
use Ultra\Projection\ProjectionPlan;
use Ultra\Projection\ProjectionPlanValidator;

final readonly class PostgresDirectProjectionRunner
{
    private const LANE = 'direct';

    public function __construct(
        private Connection $connection,
        private EventFeed $eventFeed,
        private ProjectionEnvelopeFactory $envelopeFactory,
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

        $this->connection->beginTransaction();

        try {
            $lastEventId = $this->repository->lockStateLastEventId($plan->name(), $plan->version(), self::LANE);
            $events = $this->eventFeed->readAfter($lastEventId, $limit, $plan->eventTypes);

            if ([] === $events) {
                $this->connection->commit();

                return 0;
            }

            foreach ($events as $event) {
                $plan->projection->apply($this->envelopeFactory->fromStoredEvent($event));
            }

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
