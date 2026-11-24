<?php

declare(strict_types=1);

namespace Ultra\Schema\Migration;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ultra\Schema\SchemaDefinition;

/** @internal */
final class MigrationConsole
{
    public static function create(): Application
    {
        $application = new Application('ultra-schema');
        $application->setCatchErrors(true);

        self::register($application, 'init')
            ->setDescription('Record the current schema as a baseline for an existing database')
            ->setCode(static function (InputInterface $input, OutputInterface $output): int {
                $schema = self::loadDefinition((string) $input->getArgument('schema'));
                $history = (string) $input->getArgument('history-directory');
                $baseline = new MigrationGenerator($history)->initialize($schema);

                $output->writeln(
                    sprintf('Recorded schema baseline %s/%s/snapshot.json.', $history, $baseline),
                    OutputInterface::OUTPUT_RAW,
                );

                return Command::SUCCESS;
            });

        self::register($application, 'generate')
            ->setDescription('Generate a PostgreSQL migration from the last recorded schema')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Migration name in lowercase letters and digits separated by hyphens',
            )
            ->addOption('allow-destructive', null, InputOption::VALUE_NONE, 'Allow dropping tables and columns')
            ->setCode(static function (InputInterface $input, OutputInterface $output): int {
                $schema = self::loadDefinition((string) $input->getArgument('schema'));
                $history = (string) $input->getArgument('history-directory');
                $migration = new MigrationGenerator($history)->generate(
                    $schema,
                    (string) $input->getArgument('name'),
                    true === $input->getOption('allow-destructive'),
                );

                $output->writeln(
                    null === $migration
                        ? 'No schema changes.'
                        : sprintf('Generated %s/%s/migration.sql and snapshot.json.', $history, $migration),
                    OutputInterface::OUTPUT_RAW,
                );

                return Command::SUCCESS;
            });

        return $application;
    }

    private static function register(Application $application, string $name): Command
    {
        return $application
            ->register($name)
            ->addArgument('schema', InputArgument::REQUIRED, 'PHP file returning a SchemaDefinition')
            ->addArgument(
                'history-directory',
                InputArgument::REQUIRED,
                'Directory containing the versioned migration history',
            );
    }

    private static function loadDefinition(string $path): SchemaDefinition
    {
        $resolved = realpath($path);
        if (false === $resolved || !is_file($resolved) || !is_readable($resolved)) {
            throw new \RuntimeException(sprintf('Cannot read schema definition "%s".', $path));
        }

        $definition = require $resolved;
        if (!$definition instanceof SchemaDefinition) {
            throw new \UnexpectedValueException(sprintf(
                'Schema definition "%s" must return a SchemaDefinition.',
                $path,
            ));
        }

        return $definition;
    }
}
