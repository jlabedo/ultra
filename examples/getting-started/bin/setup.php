<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GettingStarted\Database;
use Ultra\Coordination_Postgres\Lease\PostgresCoordinatorLeaseSchema;
use Ultra\Coordination_Postgres\Tracking\PostgresDirtyKeyTrackerSchema;
use Ultra\EventStore_Postgres\PostgresEventStoreQueryStreamsSchema;
use Ultra\MessageHandling_PostgresQueue\PostgresAsyncQueueSchema;
use Ultra\Projection_Postgres\PostgresProjectionSegmentSchema;

$connection = Database::connect();
$statements = [
    'CREATE TABLE tutorial_contacts (id TEXT PRIMARY KEY, name TEXT NOT NULL)',
    'CREATE TABLE tutorial_notifications (id TEXT PRIMARY KEY, body TEXT NOT NULL)',
    'CREATE TABLE tutorial_course_names (id TEXT PRIMARY KEY, name TEXT NOT NULL)',
    ...new PostgresEventStoreQueryStreamsSchema('tutorial_es_')->createStatements(),
    ...new PostgresAsyncQueueSchema('tutorial_queue_')->createStatements(),
    ...new PostgresProjectionSegmentSchema('tutorial_projection_')->createStatements(),
    ...new PostgresDirtyKeyTrackerSchema('tutorial_projection_dirty_keys')->createStatements(),
    ...new PostgresCoordinatorLeaseSchema('tutorial_projection_coordinator_lease')->createStatements(),
];
$connection->transactional(static function () use ($connection, $statements): void {
    foreach ($statements as $sql) {
        $connection->executeStatement($sql);
    }
});
echo "Tutorial tables created.\n";
