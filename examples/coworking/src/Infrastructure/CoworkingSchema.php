<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;

/**
 * Creates the tables of the three flavors side by side. The state-store tables are written by hand: nothing
 * derives DDL from #[State] metadata.
 */
final readonly class CoworkingSchema
{
    private const array STATE_STORE_TABLES = [
        'cw_ss_companies' => 'company_id TEXT PRIMARY KEY, name TEXT NOT NULL, monthly_minutes INTEGER NOT NULL',
        'cw_ss_members' => 'member_id TEXT PRIMARY KEY, company_id TEXT NOT NULL, email TEXT NOT NULL',
        'cw_ss_member_emails' => 'email TEXT PRIMARY KEY, member_id TEXT NOT NULL',
        'cw_ss_rooms' => 'room_id TEXT PRIMARY KEY, name TEXT NOT NULL',
        'cw_ss_bookings' => 'booking_id TEXT PRIMARY KEY, room_id TEXT NOT NULL, member_id TEXT NOT NULL, company_id TEXT NOT NULL, start_at TEXT NOT NULL, end_at TEXT NOT NULL, status TEXT NOT NULL',
        'cw_ss_room_days' => 'room_day_id TEXT PRIMARY KEY',
        'cw_ss_room_day_slots' => 'room_day_id TEXT NOT NULL, booking_id TEXT NOT NULL, member_id TEXT NOT NULL, company_id TEXT NOT NULL, start_at TEXT NOT NULL, end_at TEXT NOT NULL, PRIMARY KEY (room_day_id, booking_id)',
        'cw_ss_company_months' => 'company_month_id TEXT PRIMARY KEY',
        'cw_ss_company_month_charges' => 'company_month_id TEXT NOT NULL, booking_id TEXT NOT NULL, minutes INTEGER NOT NULL, PRIMARY KEY (company_month_id, booking_id)',
    ];

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private string $eventStoreTablePrefix = 'cw_es_',
    ) {}

    public function reset(): void
    {
        $eventStore = new PostgresEventStoreHStoreSchema($this->eventStoreTablePrefix);
        foreach ([...$eventStore->dropStatements(), ...$eventStore->createStatements()] as $statement) {
            $this->connection->executeStatement($statement);
        }

        foreach (self::STATE_STORE_TABLES as $table => $columns) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
            $this->connection->executeStatement(sprintf('CREATE TABLE %s (%s)', $table, $columns));
        }

        // SchemaTool::dropSchema() introspects the whole database and cannot read the event store column types.
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        foreach ($metadata as $classMetadata) {
            $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s CASCADE', $classMetadata->getTableName()));
        }
        new SchemaTool($this->entityManager)->createSchema($metadata);
    }
}
