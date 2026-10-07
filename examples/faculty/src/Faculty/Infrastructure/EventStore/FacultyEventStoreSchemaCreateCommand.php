<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\EventStore;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\EventStore_Postgres\PostgresEventStoreHStoreSchema;

#[AsCommand(
    name: 'faculty:event-store:schema:create',
    description: 'Create the optional Postgres schema for the faculty example event store.',
)]
final class FacultyEventStoreSchemaCreateCommand extends Command
{
    public function __construct(
        private readonly ?Connection $connection = null,
        private readonly string $tablePrefix = FacultyEventStoreFactory::DEFAULT_TABLE_PREFIX,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (null === $this->connection) {
            $output->writeln('<error>Doctrine DBAL is not configured.</error>');

            return self::FAILURE;
        }

        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $output->writeln(
                '<error>The faculty event store schema command requires a PostgreSQL DATABASE_URL.</error>',
            );

            return self::FAILURE;
        }

        foreach (new PostgresEventStoreHStoreSchema($this->tablePrefix)->createStatements() as $statement) {
            $this->connection->executeStatement($statement);
        }

        $output->writeln('<info>Faculty event store schema is ready.</info>');

        return self::SUCCESS;
    }
}
