<?php

declare(strict_types=1);

namespace App\Faculty\Infrastructure\EventStore;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\EventStore_Postgres\PostgresEventStoreSchema;

#[AsCommand(name: 'faculty:event-store:schema:create', description: 'Create the optional Postgres schema for the faculty example event store.')]
final class FacultyEventStoreSchemaCreateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $databaseUrl = trim((string) ($_ENV['FACULTY_DATABASE_URL'] ?? $_SERVER['FACULTY_DATABASE_URL'] ?? ''));
        if ('' === $databaseUrl) {
            $output->writeln('<error>Set FACULTY_DATABASE_URL before creating the Postgres schema.</error>');

            return self::FAILURE;
        }

        $connection = DriverManager::getConnection([
            'url' => $databaseUrl,
        ]);

        foreach ((new PostgresEventStoreSchema(FacultyEventStoreFactory::TABLE_PREFIX))->createStatements() as $statement) {
            $connection->executeStatement($statement);
        }

        $output->writeln('<info>Faculty event store schema is ready.</info>');

        return self::SUCCESS;
    }
}
