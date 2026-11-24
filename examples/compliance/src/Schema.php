<?php

declare(strict_types=1);

namespace ComplianceExample;

use Doctrine\DBAL\Connection;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Refresh\PostgresRefreshPublicationSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;

final class Schema
{
    public const string DIRTY_KEYS = 'compliance_dirty_keys';
    public const string REFRESH_REVISIONS = 'compliance_refresh_revisions';

    public static function install(Connection $connection): void
    {
        $mappedTables = new SchemaReconciler()->reconcile(new SchemaDefinition([]), ComplianceSchema::definition());

        foreach ([
            ...new PostgresEventStoreQueryStreamsSchema()->createStatements(),
            ...new PostgresProjectionSegmentSchema()->createStatements(),
            ...new PostgresDirtyKeyTrackerSchema('projection_dirty_keys')->createStatements(),
            ...new PostgresCoordinatorLeaseSchema('projection_coordinator_lease')->createStatements(),
            ...new PostgresDirtyKeyTrackerSchema(self::DIRTY_KEYS)->createStatements(),
            ...new PostgresRefreshPublicationSchema(self::REFRESH_REVISIONS)->createStatements(),
            ...new PostgresSqlGenerator()->generate($mappedTables),
            'CREATE TABLE compliance_qualification_refresh (
                account_id TEXT NOT NULL, qualification_id TEXT NOT NULL,
                ready BOOLEAN NOT NULL, after_subject TEXT, observed_counter BIGINT,
                PRIMARY KEY (account_id, qualification_id)
            )',
        ] as $statement) {
            $connection->executeStatement($statement);
        }
    }
}
