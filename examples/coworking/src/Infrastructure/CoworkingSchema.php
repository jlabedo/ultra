<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Booking\StateStore\Persistence\BookingSchema;
use App\Booking\StateStoreDirect\Persistence\BookingSchema as DirectBookingSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;
use Ultra\Schema\PostgresSqlGenerator;
use Ultra\Schema\SchemaDefinition;
use Ultra\Schema\SchemaReconciler;

/** Creates the tables of the four flavors side by side. */
final readonly class CoworkingSchema
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private string $eventStoreTablePrefix = 'cw_es_',
    ) {}

    public function reset(): void
    {
        $stateStoreSchema = SchemaDefinition::merge(BookingSchema::definition(), DirectBookingSchema::definition());
        $operations = new SchemaReconciler()->reconcile(new SchemaDefinition([]), $stateStoreSchema);
        $statements = new PostgresSqlGenerator()->generate($operations);

        $eventStore = new PostgresEventStoreHStoreSchema($this->eventStoreTablePrefix);
        foreach ([...$eventStore->dropStatements(), ...$eventStore->createStatements()] as $statement) {
            $this->connection->executeStatement($statement);
        }

        $this->connection->transactional(static function (Connection $connection) use (
            $stateStoreSchema,
            $statements,
        ): void {
            foreach ($stateStoreSchema->tables as $table) {
                $connection->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s CASCADE',
                    $connection->quoteSingleIdentifier($table->name),
                ));
            }
            foreach ($statements as $statement) {
                $connection->executeStatement($statement);
            }
        });

        // SchemaTool::dropSchema() introspects the whole database and cannot read the event store column types.
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        foreach ($metadata as $classMetadata) {
            $this->connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s CASCADE',
                $classMetadata->getTableName(),
            ));
        }
        new SchemaTool($this->entityManager)->createSchema($metadata);
    }
}
