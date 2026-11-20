<?php

declare(strict_types=1);

namespace ComplianceExample;

use Doctrine\DBAL\Connection;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Refresh\PostgresRefreshPublicationSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;

final class Schema
{
    public const string DIRTY_KEYS = 'compliance_dirty_keys';
    public const string REFRESH_REVISIONS = 'compliance_refresh_revisions';

    public static function install(Connection $connection): void
    {
        foreach ([
            ...new PostgresEventStoreQueryStreamsSchema()->createStatements(),
            ...new PostgresProjectionSegmentSchema()->createStatements(),
            ...new PostgresDirtyKeyTrackerSchema('projection_dirty_keys')->createStatements(),
            ...new PostgresCoordinatorLeaseSchema('projection_coordinator_lease')->createStatements(),
            ...new PostgresDirtyKeyTrackerSchema(self::DIRTY_KEYS)->createStatements(),
            ...new PostgresRefreshPublicationSchema(self::REFRESH_REVISIONS)->createStatements(),
            'CREATE TABLE compliance_evidence (
                account_id TEXT NOT NULL, subject_id TEXT NOT NULL, evidence_id TEXT NOT NULL, document_type TEXT NOT NULL,
                PRIMARY KEY (account_id, subject_id, evidence_id)
            )',
            'CREATE INDEX compliance_evidence_by_type ON compliance_evidence (account_id, subject_id, document_type)',
            'CREATE TABLE compliance_qualifications (
                account_id TEXT NOT NULL, qualification_id TEXT NOT NULL, requirements JSONB NOT NULL,
                PRIMARY KEY (account_id, qualification_id)
            )',
            'CREATE TABLE compliance_eligibility (
                account_id TEXT NOT NULL, subject_id TEXT NOT NULL, qualification_id TEXT NOT NULL,
                PRIMARY KEY (account_id, subject_id, qualification_id)
            )',
            'CREATE INDEX compliance_eligibility_by_qualification
                ON compliance_eligibility (account_id, qualification_id, subject_id)',
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
