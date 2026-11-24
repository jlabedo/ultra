<?php

declare(strict_types=1);

namespace App;

use App\Projection\OrderSummarySchema;
use Doctrine\DBAL\Connection;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;

final readonly class Schema
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** Creates the example schema once, in a fresh database or PostgreSQL schema. */
    public function create(): void
    {
        $this->connection->transactional(function (): void {
            $orderSummary = new SchemaReconciler()->reconcile(
                new SchemaDefinition([]),
                OrderSummarySchema::definition(),
            );
            $statements = [
                ...new PostgresEventStoreQueryStreamsSchema()->createStatements(),
                ...new PostgresProjectionSegmentSchema()->createStatements(),
                ...new PostgresDirtyKeyTrackerSchema('projection_dirty_keys')->createStatements(),
                ...new PostgresCoordinatorLeaseSchema('projection_coordinator_lease')->createStatements(),
                ...new PostgresSqlGenerator()->generate($orderSummary),
            ];
            foreach ($statements as $statement) {
                $this->connection->executeStatement($statement);
            }
        });
    }
}
